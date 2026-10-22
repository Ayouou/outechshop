<?php
/**
 * Consommables : le papier, le toner, l'encre — ce qu'on achète pour travailler
 * et qui disparaît à l'usage.
 *
 * Une **consommation** est une dépense d'un genre particulier. Elle vit dans la
 * table `depense` comme les autres, mais avec `origine = 'CONSOMMATION'`, et
 * elle porte des lignes (`depense_ligne`) qui retirent la matière du stock.
 *
 * Deux règles tiennent tout le reste :
 *
 *  1. **Le prix est figé.** Le prix d'achat de la fiche article n'est qu'une
 *     proposition : ce qui est écrit sur la ligne y reste. Changer le prix
 *     d'une rame demain ne doit pas réécrire ce qu'on a dépensé le mois
 *     dernier.
 *
 *  2. **Ça ne se totalise pas avec le reste.** L'achat du papier a déjà été
 *     saisi quelque part (dépense ordinaire ou document d'achat) : additionner
 *     les deux compterait le même argent deux fois. Les écrans séparent donc
 *     toujours les deux origines — voir `cumulsDepenses()`.
 */

/** Préfixe de numérotation des consommations, distinct de « DP ». */
const PREFIXE_CONSOMMATION = 'CS';

/**
 * Articles qu'on peut déclarer en consommation, avec le reste en stock.
 *
 * Un article non suivi en stock (`gere_stock = 0`) reste proposé : on peut
 * vouloir chiffrer ce qu'il coûte sans en tenir l'inventaire. Il est simplement
 * marqué, et aucun mouvement ne sera écrit pour lui.
 */
function articlesConsommables(string $lieuId): array
{
    return tous(
        'SELECT a.id, a.nom, a.reference, a.unite, a.gere_stock,
                a.prix_achat, a.stock_alerte,
                COALESCE(SUM(s.quantite), 0) AS stock
           FROM article a
           LEFT JOIN stock s ON s.article_id = a.id AND s.lieu_id = ?
          WHERE a.actif = 1
          GROUP BY a.id, a.nom, a.reference, a.unite, a.gere_stock,
                   a.prix_achat, a.stock_alerte
          ORDER BY a.nom',
        [$lieuId],
    );
}

/**
 * Relit les lignes postées par le formulaire (même principe que les documents :
 * le navigateur édite, le serveur recalcule et a le dernier mot sur les montants).
 *
 * Une ligne sans article ou sans quantité est ignorée plutôt que refusée : le
 * formulaire laisse volontiers traîner une ligne vide en bas.
 */
function lireLignesConsommation(string $json): array
{
    $brut = json_decode($json ?: '[]', true);
    if (!is_array($brut)) return [];

    $lignes = [];
    foreach ($brut as $ligne) {
        $articleId = ouNull($ligne['article_id'] ?? null);
        $quantite = num($ligne['quantite'] ?? 0);
        if (!$articleId || $quantite <= 0) continue;

        $lignes[] = [
            'article_id'  => $articleId,
            'variante_id' => ouNull($ligne['variante_id'] ?? null),
            'designation' => trim((string) ($ligne['designation'] ?? '')),
            'quantite'    => $quantite,
            // Le prix vient de la saisie : c'est lui qui sera figé sur la ligne.
            'prix_achat'  => max(0, num($ligne['prix_achat'] ?? 0)),
        ];
    }
    return $lignes;
}

/**
 * Complète les lignes avec ce que dit la fiche article, et calcule les totaux.
 *
 * La désignation et le prix laissés vides sont repris de la fiche ; un article
 * effacé entre-temps fait échouer la déclaration plutôt que d'écrire une ligne
 * orpheline.
 *
 * Les lignes sont relues champ par champ plutôt que prises telles quelles :
 * `lireLignesConsommation` les a déjà mises en forme quand elles viennent du
 * formulaire, mais un script ou une API peut appeler ici directement.
 *
 * @return array{lignes: array, total: float}|array{erreur: string}
 */
function preparerLignesConsommation(array $lignes): array
{
    // Une ligne sans consommable ou sans quantité est écartée : le formulaire
    // laisse volontiers traîner une ligne vide en bas.
    $retenues = [];
    foreach ($lignes as $ligne) {
        $articleId = ouNull($ligne['article_id'] ?? null);
        $quantite = num($ligne['quantite'] ?? 0);
        if ($articleId && $quantite > 0) $retenues[] = $ligne + ['article_id' => $articleId, 'quantite' => $quantite];
    }
    if (!$retenues) return ['erreur' => 'Ajoutez au moins un consommable, avec sa quantité.'];

    $params = [];
    $ids = array_values(array_unique(array_column($retenues, 'article_id')));
    $articles = [];
    foreach (tous(
        'SELECT id, nom, unite, prix_achat, gere_stock FROM article WHERE id IN ('
        . clauseIn($ids, $params) . ')',
        $params,
    ) as $article) {
        $articles[$article['id']] = $article;
    }

    $preparees = [];
    $total = 0.0;

    foreach ($retenues as $ordre => $ligne) {
        $article = $articles[$ligne['article_id']] ?? null;
        if (!$article) return ['erreur' => 'Un des consommables choisis n’existe plus.'];

        // Prix saisi à 0 : on retombe sur la fiche plutôt que d'écrire une
        // consommation gratuite, qui fausserait le coût de revient sans le dire.
        $prix = max(0, num($ligne['prix_achat'] ?? 0)) ?: num($article['prix_achat']);
        $montant = arrondir(num($ligne['quantite']) * $prix);
        $total += $montant;

        $designation = trim((string) ($ligne['designation'] ?? ''));

        $preparees[] = [
            'article_id'  => $article['id'],
            'variante_id' => ouNull($ligne['variante_id'] ?? null),
            'designation' => $designation !== '' ? $designation : $article['nom'],
            'quantite'    => num($ligne['quantite']),
            'prix_achat'  => $prix,
            'total'       => $montant,
            'ordre'       => $ordre,
            'gere_stock'  => (int) $article['gere_stock'],
        ];
    }

    return ['lignes' => $preparees, 'total' => arrondir($total)];
}

/**
 * Enregistre une consommation : écrit la dépense, ses lignes, et sort la
 * matière du stock.
 *
 * La TVA est laissée à zéro et le montant marqué payé : rien n'est dû à
 * personne, l'argent est parti le jour de l'achat. Ce qu'on note ici, c'est la
 * valeur de ce qui a été consommé.
 *
 * @return array{id: string}|array{erreur: string}
 */
function enregistrerConsommationDepense(array $session, array $entete, array $lignes): array
{
    $prepare = preparerLignesConsommation($lignes);
    if (isset($prepare['erreur'])) return $prepare;

    $designation = trim((string) ($entete['designation'] ?? ''));
    if ($designation === '') {
        $designation = count($prepare['lignes']) === 1
            ? 'Consommation — ' . $prepare['lignes'][0]['designation']
            : 'Consommation de ' . count($prepare['lignes']) . ' consommables';
    }

    $total = $prepare['total'];
    $date = horodatage($entete['date'] ?? null) ?? maintenant();

    $id = transaction(function () use ($session, $entete, $prepare, $designation, $total, $date) {
        $depenseId = inserer('depense', [
            'numero'          => numeroSuivant(PREFIXE_CONSOMMATION),
            'date'            => $date,
            'categorie_id'    => ouNull($entete['categorie_id'] ?? null),
            'lieu_id'         => $session['lieu_id'],
            'user_id'         => $session['user_id'],
            'designation'     => $designation,
            'montant_ht'      => $total,
            'tva'             => 0,
            'montant_ttc'     => $total,
            'montant_paye'    => $total,
            'statut_paiement' => 'PAYE',
            'origine'         => 'CONSOMMATION',
            'note'            => ouNull($entete['note'] ?? null),
        ]);

        $aEcrire = [];
        foreach ($prepare['lignes'] as $ligne) {
            $aEcrire[] = [
                'depense_id'  => $depenseId,
                'article_id'  => $ligne['article_id'],
                'variante_id' => $ligne['variante_id'],
                'designation' => $ligne['designation'],
                'quantite'    => $ligne['quantite'],
                'prix_achat'  => $ligne['prix_achat'],
                'total'       => $ligne['total'],
                'ordre'       => $ligne['ordre'],
            ];
        }
        insererLot('depense_ligne', $aEcrire);

        // Le stock est retiré : quantité négative, `appliquerMouvements` écarte
        // de lui-même les articles qu'on a choisi de ne pas suivre.
        appliquerMouvements(
            array_map(fn($l) => [
                'article_id'  => $l['article_id'],
                'variante_id' => $l['variante_id'],
                'quantite'    => -$l['quantite'],
            ], $prepare['lignes']),
            [
                'lieu_id'    => $session['lieu_id'],
                'type'       => 'CONSOMMATION',
                'depense_id' => $depenseId,
                'reference'  => null,
                'note'       => ouNull($entete['note'] ?? null),
                'user_id'    => $session['user_id'],
            ],
        );

        return $depenseId;
    });

    return ['id' => $id];
}

/** Lignes d'une consommation, dans l'ordre de saisie. */
function lignesConsommation(string $depenseId): array
{
    return tous(
        'SELECT dl.*, a.unite, a.nom AS article_nom
           FROM depense_ligne dl
           LEFT JOIN article a ON a.id = dl.article_id
          WHERE dl.depense_id = ? ORDER BY dl.ordre',
        [$depenseId],
    );
}

/**
 * Reprend une consommation : la matière revient au stock et la dépense
 * disparaît. `depense_ligne` part en cascade avec elle.
 */
function supprimerConsommationDepense(string $depenseId): void
{
    transaction(function () use ($depenseId) {
        $mouvements = tous(
            'SELECT article_id, variante_id, lieu_id, quantite FROM mouvement_stock WHERE depense_id = ?',
            [$depenseId],
        );

        restituerMouvements($mouvements);

        q('DELETE FROM mouvement_stock WHERE depense_id = ?', [$depenseId]);
        supprimer('depense', $depenseId);
    });
}

/**
 * Supprime une dépense, quelle que soit son origine.
 *
 * Effacer une consommation par le chemin ordinaire laisserait le stock amputé
 * de matière qui n'a jamais été consommée : la liste des dépenses passe donc
 * par ici plutôt que par `supprimer()` directement.
 */
function supprimerDepense(string $depenseId): void
{
    $origine = valeur('SELECT origine FROM depense WHERE id = ?', [$depenseId]);
    if ($origine === 'CONSOMMATION') {
        supprimerConsommationDepense($depenseId);
        return;
    }
    supprimer('depense', $depenseId);
}

/**
 * Cumuls d'une liste de dépenses, **séparés par origine**.
 *
 * C'est la fonction qui empêche le double compte : `saisie` est l'argent sorti
 * de la caisse, `consommation` la valeur de la matière partie du stock. Les
 * additionner donnerait un total qui ne veut rien dire.
 */
function cumulsDepenses(string $where, array $args): array
{
    $ligne = un(
        "SELECT
            COALESCE(SUM(CASE WHEN d.origine <> 'CONSOMMATION' THEN d.montant_ttc END), 0)  saisie_ttc,
            COALESCE(SUM(CASE WHEN d.origine <> 'CONSOMMATION' THEN d.montant_paye END), 0) saisie_paye,
            COALESCE(SUM(CASE WHEN d.origine  = 'CONSOMMATION' THEN d.montant_ttc END), 0)  conso_ttc,
            COUNT(CASE WHEN d.origine = 'CONSOMMATION' THEN 1 END)                          conso_nombre
           FROM depense d WHERE $where",
        $args,
    );

    return [
        'saisie_ttc'   => num($ligne['saisie_ttc'] ?? 0),
        'saisie_paye'  => num($ligne['saisie_paye'] ?? 0),
        'conso_ttc'    => num($ligne['conso_ttc'] ?? 0),
        'conso_nombre' => (int) ($ligne['conso_nombre'] ?? 0),
    ];
}
