<?php
/**
 * Données de démonstration : catégories, marques, articles, clients,
 * fournisseurs, quelques documents, dépenses et un catalogue public.
 *
 * Usage :  php scripts/donnees-demo.php [code-societe]
 *
 * À réserver au développement — en exploitation, on part d'une base vide créée
 * par l'écran d'installation.
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/documents.php';
require RACINE . '/app/lib/numerotation.php';
require RACINE . '/app/lib/stock.php';

require RACINE . '/app/lib/saas.php';

// La base d'une société est choisie par son code : `php scripts/XXX.php atlas`.
// Sans argument, le script travaille sur la base par défaut de config.php.
$code = $argv[1] ?? null;
if ($code !== null) {
    $societe = dansConsole(fn() => un('SELECT * FROM saas_societe WHERE code = ?', [strtolower($code)]));
    if (!$societe || empty($societe['base'])) exit("Aucun espace installé pour le code « $code »." . PHP_EOL);
    utiliserBase($societe['base']);
    echo "Base : {$societe['base']} ({$societe['nom']})" . PHP_EOL;
}

$lieu = un('SELECT * FROM lieu_affaire ORDER BY code LIMIT 1');
$admin = un("SELECT * FROM utilisateur WHERE role = 'ADMIN' ORDER BY created_at LIMIT 1");

if (!$lieu || !$admin) {
    exit("Lancez d'abord l'installation (page /installation) pour créer la société, le lieu et l'administrateur.\n");
}

$session = ['lieu_id' => $lieu['id'], 'user_id' => $admin['id']];

/** Insère si le nom n'existe pas déjà, et renvoie l'identifiant. */
function creerSiAbsent(string $table, array $donnees, string $cleUnique = 'nom'): string
{
    $existant = valeur("SELECT id FROM `$table` WHERE `$cleUnique` = ?", [$donnees[$cleUnique]]);
    return $existant ? (string) $existant : inserer($table, $donnees);
}

echo "Catégories et marques…\n";
$categories = [];
foreach (['Matériaux', 'Peinture', 'Outillage', 'Électricité'] as $nom) {
    $categories[$nom] = creerSiAbsent('categorie', ['nom' => $nom]);
}

$marques = [];
foreach (['Lafarge', 'Astral', 'Bosch'] as $nom) {
    $marques[$nom] = creerSiAbsent('marque', ['nom' => $nom]);
}

echo "Groupes…\n";
$groupeClient = creerSiAbsent('groupe_client', ['nom' => 'Revendeurs', 'remise_pourcent' => 5,
    'description' => 'Remise permanente sur toutes les ventes']);
$groupePrix = creerSiAbsent('groupe_prix', ['nom' => 'Grossistes', 'actif' => 1,
    'description' => 'Grille tarifaire réservée aux gros volumes']);

echo "Articles…\n";
$articles = [
    ['reference' => 'CIM50', 'nom' => 'Ciment CPJ 45 — sac 50 kg', 'categorie' => 'Matériaux', 'marque' => 'Lafarge',
     'prix_achat' => 62, 'prix_vente' => 85, 'unite' => 'Sac', 'stock' => 240, 'code_barre' => '6111234567890'],
    ['reference' => 'PEI5L', 'nom' => 'Peinture blanche mate 5 L', 'categorie' => 'Peinture', 'marque' => 'Astral',
     'prix_achat' => 170, 'prix_vente' => 240, 'unite' => 'Pièce', 'stock' => 45, 'code_barre' => '6111234567891'],
    ['reference' => 'PERC800', 'nom' => 'Perceuse à percussion 800 W', 'categorie' => 'Outillage', 'marque' => 'Bosch',
     'prix_achat' => 640, 'prix_vente' => 890, 'unite' => 'Pièce', 'stock' => 12, 'code_barre' => '6111234567892'],
    ['reference' => 'CAB25', 'nom' => 'Câble électrique 2,5 mm² — 100 m', 'categorie' => 'Électricité', 'marque' => null,
     'prix_achat' => 380, 'prix_vente' => 520, 'unite' => 'Rouleau', 'stock' => 30, 'code_barre' => '6111234567893'],
    ['reference' => 'SABLE', 'nom' => 'Sable de construction', 'categorie' => 'Matériaux', 'marque' => null,
     'prix_achat' => 90, 'prix_vente' => 130, 'unite' => 'Kg', 'stock' => 5000, 'code_barre' => null],
];

$idsArticles = [];
foreach ($articles as $article) {
    $existant = valeur('SELECT id FROM article WHERE reference = ?', [$article['reference']]);
    if ($existant) { $idsArticles[$article['reference']] = (string) $existant; continue; }

    $id = transaction(function () use ($article, $categories, $marques, $session) {
        $articleId = inserer('article', [
            'reference'    => $article['reference'],
            'code_barre'   => $article['code_barre'],
            'nom'          => $article['nom'],
            'unite'        => $article['unite'],
            'prix_achat'   => $article['prix_achat'],
            'prix_vente'   => $article['prix_vente'],
            'tva'          => 20,
            'stock_alerte' => 10,
            'categorie_id' => $categories[$article['categorie']] ?? null,
            'marque_id'    => $article['marque'] ? ($marques[$article['marque']] ?? null) : null,
        ]);

        appliquerMouvement([
            'article_id' => $articleId,
            'lieu_id'    => $session['lieu_id'],
            'type'       => 'AJUSTEMENT',
            'quantite'   => $article['stock'],
            'note'       => 'Stock initial (démonstration)',
            'user_id'    => $session['user_id'],
        ]);

        return $articleId;
    });

    $idsArticles[$article['reference']] = $id;
}

echo "Tiers…\n";
$tiers = [
    ['code' => 'CL2026001', 'type' => 'CLIENT', 'nom' => 'Ahmed Benali', 'nom_entreprise' => 'Benali Travaux',
     'telephone' => '0661234567', 'ville' => 'Casablanca', 'ice' => '001234567000045',
     'groupe_id' => $groupeClient, 'plafond_credit' => 20000],
    ['code' => 'CL2026002', 'type' => 'CLIENT', 'nom' => 'Fatima Zahra Idrissi', 'nom_entreprise' => null,
     'telephone' => '0662345678', 'ville' => 'Rabat', 'ice' => null,
     'groupe_prix_id' => $groupePrix, 'plafond_credit' => 5000],
    ['code' => 'FR2026001', 'type' => 'FOURNISSEUR', 'nom' => 'Comptoir des Matériaux', 'nom_entreprise' => 'CDM SARL',
     'telephone' => '0522334455', 'ville' => 'Casablanca', 'ice' => '000987654000032'],
];

$idsTiers = [];
foreach ($tiers as $t) {
    $idsTiers[$t['code']] = creerSiAbsent('tiers', $t, 'code');
}

echo "Catégories de dépenses et dépenses…\n";
$categorieDepense = creerSiAbsent('categorie_depense', ['nom' => 'Charges fixes']);

if ((int) valeur('SELECT COUNT(*) FROM depense') === 0) {
    inserer('depense', [
        'numero'          => numeroSuivant('DP'),
        'date'            => maintenant(),
        'categorie_id'    => $categorieDepense,
        'lieu_id'         => $lieu['id'],
        'user_id'         => $admin['id'],
        'designation'     => 'Loyer du magasin',
        'montant_ht'      => 4000,
        'tva'             => 0,
        'montant_ttc'     => 4000,
        'montant_paye'    => 4000,
        'statut_paiement' => 'PAYE',
        'mode'            => 'VIREMENT',
        'recurrente'      => 1,
        'beneficiaire'    => 'SCI Al Manar',
    ]);
}

echo "Catalogue public…\n";
if ((int) valeur('SELECT COUNT(*) FROM catalogue') === 0) {
    inserer('catalogue', [
        'slug'          => slugifier($lieu['nom']) ?: 'catalogue',
        'lieu_id'       => $lieu['id'],
        'titre'         => 'Catalogue ' . $lieu['nom'],
        'sous_titre'    => 'Matériaux, peinture et outillage',
        'actif'         => 1,
        'afficher_prix' => 1,
    ]);
}

echo "Documentation…\n";
if ((int) valeur('SELECT COUNT(*) FROM documentation') === 0) {
    inserer('documentation', [
        'titre'    => 'Procédure de retour marchandise',
        'type'     => 'PROCEDURE',
        'categorie'=> 'Ventes',
        'contenu'  => "1. Vérifier la facture ou le bon d'origine.\n"
            . "2. Contrôler l'état de la marchandise.\n"
            . "3. Créer un avoir depuis l'historique du client.",
        'user_id'  => $admin['id'],
    ]);
}

echo "Chèque de démonstration…\n";
if ((int) valeur('SELECT COUNT(*) FROM effet') === 0) {
    inserer('effet', [
        'type'          => 'CHEQUE',
        'sens'          => 'RECU',
        'numero'        => '1234567',
        'banque'        => 'Attijariwafa Bank',
        'montant'       => 3500,
        'date_emission' => maintenant(),
        'date_echeance' => (new DateTime('+21 days'))->format('Y-m-d H:i:s.v'),
        'tiers_id'      => $idsTiers['CL2026001'] ?? null,
    ]);
}

echo "Atelier d'impression…\n";

$machines = [
    ['code' => 'TRACEUR-1', 'nom' => 'Traceur éco-solvant 3,20 m', 'type' => 'IMPRESSION', 'laize' => 3.2],
    ['code' => 'DTF-1',     'nom' => 'Imprimante DTF 60 cm',       'type' => 'DTF',        'laize' => 0.6],
    ['code' => 'PLOT-1',    'nom' => 'Plotter de découpe 1,20 m',  'type' => 'DECOUPE',    'laize' => 1.2],
];

$idsMachines = [];
foreach ($machines as $machine) {
    $existante = valeur('SELECT id FROM machine WHERE code = ?', [$machine['code']]);
    $idsMachines[$machine['code']] = $existante
        ? (string) $existante
        : inserer('machine', $machine + ['actif' => 1]);
}

// Les supports d'impression sont des articles ordinaires, suivis en stock et
// comptés au mètre : c'est leur laize qui les distingue d'une marchandise.
$supports = [
    ['reference' => 'BACHE510', 'nom' => 'Bâche 510 g/m² — rouleau 3,20 m', 'laize' => 3.2,
     'prix_achat' => 22, 'prix_vente' => 55, 'stock' => 150],
    ['reference' => 'VINYLMONO', 'nom' => 'Vinyle monomère brillant 1,37 m', 'laize' => 1.37,
     'prix_achat' => 18, 'prix_vente' => 48, 'stock' => 80],
    ['reference' => 'FILMDTF', 'nom' => 'Film DTF — rouleau 60 cm', 'laize' => 0.6,
     'prix_achat' => 12, 'prix_vente' => 35, 'stock' => 120],
];

foreach ($supports as $support) {
    $existant = valeur('SELECT id FROM article WHERE reference = ?', [$support['reference']]);
    if ($existant) { $idsArticles[$support['reference']] = (string) $existant; continue; }

    $idsArticles[$support['reference']] = transaction(function () use ($support, $session) {
        $articleId = inserer('article', [
            'reference'    => $support['reference'],
            'nom'          => $support['nom'],
            'unite'        => 'Mètre',
            'laize'        => $support['laize'],
            'prix_achat'   => $support['prix_achat'],
            'prix_vente'   => $support['prix_vente'],
            'tva'          => 20,
            'stock_alerte' => 20,
        ]);

        appliquerMouvement([
            'article_id' => $articleId,
            'lieu_id'    => $session['lieu_id'],
            'type'       => 'AJUSTEMENT',
            'quantite'   => $support['stock'],
            'note'       => 'Stock initial (démonstration)',
            'user_id'    => $session['user_id'],
        ]);

        return $articleId;
    });
}

/** Sortie de matière de démonstration : utilisée + chute, en deux mouvements. */
$sortir = function (array $donnees) use ($session) {
    $consommationId = inserer('consommation', [
        'travail_id' => $donnees['travail_id'] ?? null,
        'article_id' => $donnees['article_id'],
        'lieu_id'    => $session['lieu_id'],
        'machine_id' => $donnees['machine_id'] ?? null,
        'user_id'    => $session['user_id'],
        'date'       => $donnees['date'] ?? maintenant(),
        'quantite'   => $donnees['quantite'],
        'chute'      => $donnees['chute'],
        'motif'      => $donnees['motif'] ?? 'PRODUCTION',
        'note'       => $donnees['note'] ?? null,
    ]);

    foreach ([['PRODUCTION', $donnees['quantite']], ['CHUTE', $donnees['chute']]] as [$type, $quantite]) {
        if ($quantite <= 0) continue;
        appliquerMouvement([
            'article_id'      => $donnees['article_id'],
            'lieu_id'         => $session['lieu_id'],
            'type'            => $type,
            'quantite'        => -$quantite,
            'consommation_id' => $consommationId,
            'reference'       => $donnees['reference'] ?? null,
            'user_id'         => $session['user_id'],
        ]);
    }

    return $consommationId;
};

if ((int) valeur('SELECT COUNT(*) FROM travail') === 0) {
    $client = valeur('SELECT id FROM tiers ORDER BY created_at LIMIT 1');

    // Une bâche 3 × 2 m : 6 m² à 60 dh, TVA 20 % — le dossier est sur machine.
    $numero = numeroSuivant('OT');
    $travailId = inserer('travail', [
        'numero'        => $numero,
        'date'          => (new DateTime('-2 days'))->format('Y-m-d H:i:s.v'),
        'date_promise'  => (new DateTime('+1 day'))->format('Y-m-d H:i:s.v'),
        'designation'   => 'Bâche 3 × 2 m — Café Al Amal',
        'description'   => "Fond bleu, œillets tous les 50 cm, livraison sur place.",
        'etape'         => 'IMPRESSION',
        'priorite'      => 'URGENTE',
        'tiers_id'      => $client ?: null,
        'lieu_id'       => $session['lieu_id'],
        'user_id'       => $session['user_id'],
        'designer_id'   => $session['user_id'],
        'machiniste_id' => $session['user_id'],
        'machine_id'    => $idsMachines['TRACEUR-1'],
        'total_ht'      => 360,
        'total_tva'     => 72,
        'total_ttc'     => 432,
        'avance'        => 200,
    ]);

    inserer('travail_ligne', [
        'travail_id'    => $travailId,
        'designation'   => 'Bâche 510 g impression quadri',
        'mode'          => 'M2',
        'largeur'       => 3,
        'hauteur'       => 2,
        'quantite'      => 1,
        'unites'        => 6,
        'prix_unitaire' => 60,
        'tva'           => 20,
        'total_ht'      => 360,
        'total_ttc'     => 432,
        'ordre'         => 0,
    ]);

    foreach ([['ACCUEIL', '-2 days', 'Commande reçue'],
              ['DESIGN', '-1 day', 'Maquette envoyée au client'],
              ['IMPRESSION', '-2 hours', 'BAT validé, lancement machine']] as [$etape, $quand, $note]) {
        inserer('travail_etape', [
            'travail_id' => $travailId,
            'etape'      => $etape,
            'user_id'    => $session['user_id'],
            'note'       => $note,
            'created_at' => (new DateTime($quand))->format('Y-m-d H:i:s.v'),
        ]);
    }

    // 2,20 m tirés du rouleau pour 2 m utiles : la reprise part en chute.
    $sortir([
        'travail_id' => $travailId,
        'article_id' => $idsArticles['BACHE510'],
        'machine_id' => $idsMachines['TRACEUR-1'],
        'quantite'   => 2.2,
        'chute'      => 0.4,
        'motif'      => 'PRODUCTION',
        'reference'  => $numero,
        'note'       => 'Marges de coupe',
    ]);

    // Vente de DTF au comptoir : pas de dossier, mais la matière sort quand même.
    $sortir([
        'article_id' => $idsArticles['FILMDTF'],
        'machine_id' => $idsMachines['DTF-1'],
        'quantite'   => 5,
        'chute'      => 0.3,
        'motif'      => 'ERREUR_IMPRESSION',
        'note'       => 'Vente au mètre — reprise après bourrage',
    ]);
}

echo "\nDonnées de démonstration en place.\n";

// Le catalogue de démonstration s'adresse publiquement : son nom est réservé
// dans le registre, faute de quoi `/c/<nom>` ne mènerait nulle part.
if (isset($societe) && $societe) {
    foreach (tous('SELECT slug, lieu_id FROM catalogue') as $catalogue) {
        if (adresseDisponible($catalogue['slug'], $societe['id'])) {
            reserverAdressePublique($catalogue['slug'], $societe['id'], $catalogue['lieu_id']);
            echo "Catalogue public : /c/{$catalogue['slug']}", PHP_EOL;
        } else {
            echo "Adresse « {$catalogue['slug']} » déjà prise : catalogue non publié.", PHP_EOL;
        }
    }
}
