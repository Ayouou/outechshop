<?php
/**
 * Synthèses chiffrées : caisse, activité (rapports) et tableau de bord.
 *
 * Ces calculs servent deux fois — à l'écran et sur le papier. Les tenir ici
 * plutôt que dans les pages garantit qu'un rapport imprimé annonce exactement
 * les mêmes montants que la page qui l'a lancé.
 *
 * Toutes les fonctions travaillent sur un lieu d'affaires et une période
 * bornée par `debutJour()` / `finJour()`.
 */

/* ------------------------------------------------------------- la période */

/**
 * Bornes de la période demandée en query string.
 *
 * `du` / `au` la définissent ; `jour` (hérité de l'ancienne caisse, qui ne
 * connaissait qu'une journée) vaut pour les deux à la fois. Une période à
 * l'envers est remise à l'endroit plutôt que de ne rien rendre du tout.
 */
function periodeDemandee(string $defautDu = 'now', string $defautAu = 'now'): array
{
    $jour = param('jour');
    $du = param('du') ?: $jour ?: $defautDu;
    $au = param('au') ?: $jour ?: $defautAu;

    if (substr((string) $au, 0, 10) < substr((string) $du, 0, 10)) [$du, $au] = [$au, $du];

    return [debutJour($du), finJour($au)];
}

/** Query string `du=…&au=…`, à recoller sur un lien (impression, filtre). */
function requetePeriode(string $du, string $au): string
{
    return 'du=' . substr($du, 0, 10) . '&au=' . substr($au, 0, 10);
}

/** « du 01/08/2026 au 20/08/2026 », « du 20/08/2026 » si la période tient en un jour. */
function libellePeriode(string $du, string $au): string
{
    return periodeUnJour($du, $au)
        ? 'du ' . dateFr($du)
        : 'du ' . dateFr($du) . ' au ' . dateFr($au);
}

function periodeUnJour(string $du, string $au): bool
{
    return substr($du, 0, 10) === substr($au, 0, 10);
}

function periodeAujourdhui(string $du, string $au): bool
{
    return periodeUnJour($du, $au) && substr($du, 0, 10) === date('Y-m-d');
}

/**
 * Courbe jour par jour : un point par journée de la période, même celles sans
 * vente — sinon le graphique mentirait sur le rythme des ventes. La clé est
 * en heure locale, sinon les journées se décaleraient d'un cran.
 */
function pointsParJour(array $lignes, string $du, string $au, string $cleValeur = 'total_ttc'): array
{
    $parJour = [];
    for ($d = new DateTime(substr($du, 0, 10)); $d->format('Y-m-d') <= substr($au, 0, 10); $d->modify('+1 day')) {
        $parJour[$d->format('Y-m-d')] = 0.0;
    }
    foreach ($lignes as $ligne) {
        $cle = substr((string) $ligne['date'], 0, 10);
        if (isset($parJour[$cle])) $parJour[$cle] += num($ligne[$cleValeur]);
    }

    $points = [];
    foreach ($parJour as $cle => $valeur) {
        $points[] = ['etiquette' => substr($cle, 8, 2) . '/' . substr($cle, 5, 2), 'valeur' => $valeur];
    }
    return $points;
}

/* ---------------------------------------------------------------- la caisse */

/**
 * Mouvements d'argent de la période.
 *
 * Seuls trois types de documents portent des règlements : les ventes encaissées
 * (facture, bon de livraison) font entrer l'argent, l'achat réglé au comptoir le
 * fait sortir — comme les dépenses payées.
 */
function syntheseCaisse(string $lieuId, string $du, string $au): array
{
    $periode = [$lieuId, $du, $au];

    $reglements = tous(
        "SELECT p.*, d.id AS document_id, d.numero, d.type AS document_type, t.nom AS tiers_nom, us.nom AS user_nom
           FROM paiement p
           JOIN document d ON d.id = p.document_id
           LEFT JOIN tiers t ON t.id = d.tiers_id
           LEFT JOIN utilisateur us ON us.id = p.user_id
          WHERE d.lieu_id = ? AND d.statut <> 'ANNULE' AND p.date BETWEEN ? AND ?
          ORDER BY p.date ASC",
        $periode,
    );

    $ventes = tous(
        'SELECT d.*, t.nom AS tiers_nom FROM document d
           LEFT JOIN tiers t ON t.id = d.tiers_id
          WHERE d.lieu_id = ? AND d.date BETWEEN ? AND ? AND (' . conditionVentesReelles('d') . ')
          ORDER BY d.date ASC',
        $periode,
    );

    $depenses = tous(
        // `origine` écarte les consommations de stock : elles sont marquées payées
        // parce que rien n'est dû, mais aucun billet n'est sorti du tiroir ce jour-là.
        "SELECT dep.*, c.nom AS categorie_nom FROM depense dep
           LEFT JOIN categorie_depense c ON c.id = dep.categorie_id
          WHERE dep.lieu_id = ? AND dep.date BETWEEN ? AND ? AND dep.montant_paye > 0
            AND dep.origine <> 'CONSOMMATION'
          ORDER BY dep.date ASC",
        $periode,
    );

    $entrees = array_values(array_filter($reglements, fn($r) => in_array($r['document_type'], ['FACTURE', 'BON_LIVRAISON'], true)));
    $sortiesAchats = array_values(array_filter($reglements, fn($r) => $r['document_type'] === 'ACHAT'));

    $totalEncaisse = 0.0;
    $parMode = [];
    foreach ($entrees as $reglement) {
        $totalEncaisse += num($reglement['montant']);
        $mode = $reglement['mode'];
        $parMode[$mode] = $parMode[$mode] ?? ['nombre' => 0, 'total' => 0.0];
        $parMode[$mode]['nombre']++;
        $parMode[$mode]['total'] += num($reglement['montant']);
    }

    $especesEntrees = $parMode['ESPECES']['total'] ?? 0;
    $especesSorties = 0.0;
    foreach ($sortiesAchats as $reglement) {
        if ($reglement['mode'] === 'ESPECES') $especesSorties += num($reglement['montant']);
    }
    foreach ($depenses as $depense) {
        if ($depense['mode'] === 'ESPECES') $especesSorties += num($depense['montant_paye']);
    }

    $totalVentes = 0.0;
    $resteAEncaisser = 0.0;
    foreach ($ventes as $vente) {
        $totalVentes += num($vente['total_ttc']);
        $resteAEncaisser += max(0, num($vente['total_ttc']) - num($vente['montant_paye']));
    }

    return compact(
        'reglements', 'ventes', 'depenses', 'entrees', 'sortiesAchats', 'parMode',
        'totalEncaisse', 'especesEntrees', 'especesSorties', 'totalVentes', 'resteAEncaisser',
    );
}

/* -------------------------------------------------------------- l'activité */

/**
 * Chiffre d'affaires, marge, meilleurs articles et clients, stock valorisé.
 *
 * Les bons de livraison non encore regroupés comptent comme des ventes ; une
 * fois facturés, c'est leur facture qui les porte.
 */
function syntheseActivite(string $lieuId, string $du, string $au): array
{
    $args = [$lieuId, $du, $au];
    $ventesReelles = conditionVentesReelles('d');

    $factures = tous(
        "SELECT d.*, t.nom AS tiers_nom FROM document d
           LEFT JOIN tiers t ON t.id = d.tiers_id
          WHERE d.lieu_id = ? AND d.date BETWEEN ? AND ? AND ($ventesReelles)",
        $args,
    );

    $lignesVendues = tous(
        "SELECT l.*, a.id AS aid, a.nom AS article_nom, a.prix_achat FROM ligne_document l
           JOIN document d ON d.id = l.document_id
           JOIN article a ON a.id = l.article_id
          WHERE d.lieu_id = ? AND d.date BETWEEN ? AND ? AND ($ventesReelles)",
        $args,
    );

    $achats = un(
        "SELECT COALESCE(SUM(total_ttc), 0) ttc, COALESCE(SUM(total_ht), 0) ht, COUNT(*) n
           FROM document d
          WHERE d.lieu_id = ? AND d.date BETWEEN ? AND ? AND d.type = 'ACHAT' AND d.statut <> 'ANNULE'",
        $args,
    );

    $depenses = un(
        // Argent sorti seulement : la matière consommée a déjà été payée à l'achat.
        "SELECT COALESCE(SUM(montant_ttc), 0) ttc, COUNT(*) n FROM depense
          WHERE lieu_id = ? AND date BETWEEN ? AND ? AND origine <> 'CONSOMMATION'",
        $args,
    );

    $stocks = tous(
        'SELECT s.quantite, a.prix_achat, a.prix_vente FROM stock s
           JOIN article a ON a.id = s.article_id WHERE s.lieu_id = ?',
        [$lieuId],
    );

    $caHT = 0.0; $caTTC = 0.0; $encaisse = 0.0;
    foreach ($factures as $facture) {
        $caHT += num($facture['total_ht']) - num($facture['remise']);
        $caTTC += num($facture['total_ttc']);
        $encaisse += num($facture['montant_paye']);
    }

    // Coût d'achat des marchandises vendues, pour la marge brute.
    $coutVentes = 0.0;
    $parArticle = [];
    foreach ($lignesVendues as $ligne) {
        $quantite = num($ligne['quantite']);
        $coutVentes += $quantite * num($ligne['prix_achat']);

        $cle = $ligne['aid'];
        $parArticle[$cle] = $parArticle[$cle] ?? ['nom' => $ligne['article_nom'], 'quantite' => 0, 'ca' => 0, 'marge' => 0];
        $parArticle[$cle]['quantite'] += $quantite;
        $parArticle[$cle]['ca'] += num($ligne['total_ht']);
        $parArticle[$cle]['marge'] += num($ligne['total_ht']) - $quantite * num($ligne['prix_achat']);
    }
    $margeBrute = $caHT - $coutVentes;

    uasort($parArticle, fn($a, $b) => $b['ca'] <=> $a['ca']);
    $topArticles = array_slice($parArticle, 0, 10, true);

    $parClient = [];
    foreach ($factures as $facture) {
        $cle = $facture['tiers_id'] ?? 'passage';
        $parClient[$cle] = $parClient[$cle] ?? ['nom' => $facture['tiers_nom'] ?? 'Client de passage', 'documents' => 0, 'ca' => 0];
        $parClient[$cle]['documents']++;
        $parClient[$cle]['ca'] += num($facture['total_ttc']);
    }
    uasort($parClient, fn($a, $b) => $b['ca'] <=> $a['ca']);
    $topClients = array_slice($parClient, 0, 10, true);

    $points = pointsParJour($factures, $du, $au);

    $valeurStock = 0.0; $valeurStockVente = 0.0;
    foreach ($stocks as $stock) {
        $valeurStock += num($stock['quantite']) * num($stock['prix_achat']);
        $valeurStockVente += num($stock['quantite']) * num($stock['prix_vente']);
    }

    $totalAchats = num($achats['ttc']);
    $totalDepenses = num($depenses['ttc']);
    $resultat = $margeBrute - $totalDepenses;

    return compact(
        'factures', 'achats', 'depenses', 'caHT', 'caTTC', 'encaisse', 'coutVentes', 'margeBrute',
        'topArticles', 'topClients', 'points', 'valeurStock', 'valeurStockVente',
        'totalAchats', 'totalDepenses', 'resultat',
    );
}

/* --------------------------------------------------------- tableau de bord */

/** Tuiles du tableau de bord : leur ordre est aussi celui de l'impression. */
const TUILES_TABLEAU_DE_BORD = [
    ['cle' => 'ventes', 'libelle' => 'Total des ventes', 'icone' => 'recu', 'couleur' => 'bg-blue-500'],
    ['cle' => 'net', 'libelle' => 'Net', 'icone' => 'billets', 'couleur' => 'bg-emerald-500'],
    ['cle' => 'impayes', 'libelle' => 'Factures impayées', 'icone' => 'alerte', 'couleur' => 'bg-amber-500'],
    ['cle' => 'retours', 'libelle' => 'Total des retours', 'icone' => 'flux', 'couleur' => 'bg-rose-500'],
    ['cle' => 'achats', 'libelle' => 'Total des achats', 'icone' => 'panier', 'couleur' => 'bg-indigo-500'],
    ['cle' => 'achatsImpayes', 'libelle' => 'Achats impayés', 'icone' => 'alerte', 'couleur' => 'bg-orange-500'],
    ['cle' => 'retoursAchat', 'libelle' => "Retour d'achat total", 'icone' => 'flux', 'couleur' => 'bg-cyan-600'],
    ['cle' => 'depenses', 'libelle' => 'Dépenses', 'icone' => 'portefeuille', 'couleur' => 'bg-violet-500'],
];

function syntheseTableauDeBord(string $lieuId, string $du, string $au): array
{
    $ventesReelles = conditionVentesReelles('d');
    $base = 'd.lieu_id = ? AND d.date BETWEEN ? AND ?';
    $args = [$lieuId, $du, $au];

    $ventes = un("SELECT COALESCE(SUM(d.total_ttc), 0) total, COUNT(*) n
                    FROM document d WHERE $base AND ($ventesReelles)", $args);

    $somme = function (string $condition) use ($base, $args) {
        return num(valeur("SELECT COALESCE(SUM(d.total_ttc), 0) FROM document d WHERE $base AND $condition", $args));
    };

    $totalVentes = num($ventes['total']);
    $totalRetours = $somme("d.type = 'AVOIR' AND d.statut <> 'ANNULE'");
    $totalAchats = $somme("d.type = 'ACHAT' AND d.statut <> 'ANNULE'");
    $totalRetoursAchat = $somme("d.type = 'RETOUR_ACHAT' AND d.statut <> 'ANNULE'");

    $totalDepenses = num(valeur(
        "SELECT COALESCE(SUM(montant_ttc), 0) FROM depense
          WHERE lieu_id = ? AND date BETWEEN ? AND ? AND origine <> 'CONSOMMATION'",
        $args,
    ));

    $impayes = un("SELECT COALESCE(SUM(d.total_ttc), 0) du, COALESCE(SUM(d.montant_paye), 0) paye
                     FROM document d
                    WHERE $base AND ($ventesReelles) AND d.statut_paiement <> 'PAYE'", $args);

    $achatsImpayes = un("SELECT COALESCE(SUM(d.total_ttc), 0) du, COALESCE(SUM(d.montant_paye), 0) paye
                           FROM document d
                          WHERE $base AND d.type = 'ACHAT' AND d.statut <> 'ANNULE'
                            AND d.statut_paiement <> 'PAYE'", $args);

    $valeurs = [
        'ventes'        => $totalVentes,
        'net'           => $totalVentes - $totalRetours,
        'impayes'       => num($impayes['du']) - num($impayes['paye']),
        'retours'       => $totalRetours,
        'achats'        => $totalAchats,
        'achatsImpayes' => num($achatsImpayes['du']) - num($achatsImpayes['paye']),
        'retoursAchat'  => $totalRetoursAchat,
        'depenses'      => $totalDepenses,
    ];

    $points = pointsParJour(
        tous("SELECT d.date, d.total_ttc FROM document d WHERE $base AND ($ventesReelles)", $args),
        $du,
        $au,
    );

    $derniersDocs = tous(
        "SELECT d.*, t.nom AS tiers_nom FROM document d
           LEFT JOIN tiers t ON t.id = d.tiers_id
          WHERE d.lieu_id = ? AND d.type IN ('FACTURE', 'BON_LIVRAISON', 'ACHAT')
          ORDER BY d.created_at DESC LIMIT 8",
        [$lieuId],
    );

    $stockCritique = tous(
        'SELECT s.id, s.quantite, s.article_id, a.nom, a.stock_alerte
           FROM stock s JOIN article a ON a.id = s.article_id
          WHERE s.lieu_id = ? AND a.gere_stock = 1 AND a.actif = 1 AND s.quantite <= a.stock_alerte
          ORDER BY s.quantite ASC LIMIT 6',
        [$lieuId],
    );

    $effetsProches = tous(
        "SELECT e.*, t.nom AS tiers_nom FROM effet e
           LEFT JOIN tiers t ON t.id = e.tiers_id
          WHERE e.statut = 'EN_ATTENTE' ORDER BY e.date_echeance ASC LIMIT 6",
    );

    return compact('valeurs', 'points', 'ventes', 'derniersDocs', 'stockCritique', 'effetsProches');
}
