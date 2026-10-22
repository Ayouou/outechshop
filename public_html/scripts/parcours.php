<?php
/**
 * Parcours fonctionnel : rejoue les règles métier délicates de bout en bout
 * (achat, conversion, regroupement, retour, inventaire) et vérifie leurs effets
 * sur le stock et les règlements.
 *
 * Usage :  php scripts/parcours.php [code-societe]
 *
 * Le script écrit dans la base configurée : à réserver au développement.
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/documents.php';
require RACINE . '/app/lib/calculs.php';
require RACINE . '/app/lib/numerotation.php';
require RACINE . '/app/lib/stock.php';
require RACINE . '/app/lib/ventes.php';
require RACINE . '/app/lib/facturation.php';
require RACINE . '/app/lib/retours.php';
require RACINE . '/app/lib/journal.php';
require RACINE . '/app/lib/operations.php';

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

// `operations.php` s'appuie sur ces helpers, fournis par le routeur en HTTP.
function ouNull($valeur): ?string
{
    if ($valeur === null) return null;
    $texte = trim((string) $valeur);
    return $texte === '' ? null : $texte;
}
function devise(): string
{
    return (string) (valeur('SELECT devise FROM societe LIMIT 1') ?: 'dhs');
}

$lieu = un('SELECT * FROM lieu_affaire ORDER BY code LIMIT 1');
$admin = un("SELECT * FROM utilisateur WHERE role = 'ADMIN' LIMIT 1");
if (!$lieu || !$admin) exit("Base non installée.\n");

$session = ['lieu_id' => $lieu['id'], 'user_id' => $admin['id']];

$reussis = 0;
$echoues = 0;

function verifier(string $libelle, $attendu, $obtenu): void
{
    global $reussis, $echoues;
    // Comparaison au centime : les décimaux MySQL reviennent en chaînes.
    $ok = is_numeric($attendu) && is_numeric($obtenu)
        ? abs((float) $attendu - (float) $obtenu) < 0.005
        : $attendu === $obtenu;

    if ($ok) { $reussis++; echo "✓ $libelle\n"; return; }
    $echoues++;
    echo "✗ $libelle — attendu " . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true) . "\n";
}

$stockDe = fn(string $reference) => num(valeur(
    'SELECT COALESCE(SUM(s.quantite), 0) FROM stock s JOIN article a ON a.id = s.article_id
      WHERE a.reference = ? AND s.lieu_id = ?',
    [$reference, $lieu['id']],
));

$article = un('SELECT * FROM article WHERE reference = ? ', ['CIM50'])
    ?? un('SELECT * FROM article ORDER BY created_at LIMIT 1');
$reference = $article['reference'];
$fournisseur = un("SELECT * FROM tiers WHERE type = 'FOURNISSEUR' LIMIT 1");
$client = un("SELECT * FROM tiers WHERE type = 'CLIENT' LIMIT 1");

echo "\n— Achat : le stock entre —\n";
$avant = $stockDe($reference);

$achat = creerDocument($session, 'ACHAT', ['tiers_id' => $fournisseur['id']], [[
    'article_id' => $article['id'], 'variante_id' => null, 'designation' => $article['nom'],
    'quantite' => 20, 'prix_unitaire' => num($article['prix_achat']), 'remise' => 0, 'tva' => 20,
]]);
verifier('achat créé', true, isset($achat['id']));
verifier('stock augmenté de 20', $avant + 20, $stockDe($reference));

echo "\n— Devis → facture : le stock ne sort qu'une fois —\n";
$avant = $stockDe($reference);

$devis = creerDocument($session, 'DEVIS', ['tiers_id' => $client['id']], [[
    'article_id' => $article['id'], 'variante_id' => null, 'designation' => $article['nom'],
    'quantite' => 5, 'prix_unitaire' => num($article['prix_vente']), 'remise' => 0, 'tva' => 20,
]]);
verifier('devis sans effet sur le stock', $avant, $stockDe($reference));

$facture = convertirDocument($session, $devis['id'], 'FACTURE');
verifier('facture issue du devis', true, isset($facture['id']));
verifier('stock sorti une seule fois', $avant - 5, $stockDe($reference));
verifier('devis passé en converti', 'CONVERTI', valeur('SELECT statut FROM document WHERE id = ?', [$devis['id']]));

echo "\n— Bon de livraison encaissé, puis regroupé en facture —\n";
$avant = $stockDe($reference);

$bon = creerDocument($session, 'BON_LIVRAISON', [
    'tiers_id' => $client['id'], 'montant_paye' => 300, 'mode_paiement' => 'ESPECES',
], [[
    'article_id' => $article['id'], 'variante_id' => null, 'designation' => $article['nom'],
    'quantite' => 3, 'prix_unitaire' => 100, 'remise' => 0, 'tva' => 20,
]]);
verifier('stock sorti par le bon', $avant - 3, $stockDe($reference));

$avantRegroupement = $stockDe($reference);
$regroupee = regrouperEnFacture($session, [$bon['id']]);
verifier('facture de regroupement créée', true, isset($regroupee['id']));
verifier('stock inchangé par le regroupement', $avantRegroupement, $stockDe($reference));

$factureRegroupee = un('SELECT * FROM document WHERE id = ?', [$regroupee['id']]);
$bonApres = un('SELECT * FROM document WHERE id = ?', [$bon['id']]);
verifier('règlement déplacé sur la facture', 300, num($factureRegroupee['montant_paye']));
verifier('bon vidé de son règlement', 0, num($bonApres['montant_paye']));
verifier('bon marqué converti', 'CONVERTI', $bonApres['statut']);
verifier('total de la facture conservé', 360, num($factureRegroupee['total_ttc']));

echo "\n— Retour d'achat : borné par ce qui a été acheté —\n";
$refus = verifierRetour('RETOUR_ACHAT', $fournisseur['id'], $lieu['id'], [[
    'article_id' => $article['id'], 'variante_id' => null, 'quantite' => 9999,
]]);
verifier('retour excessif refusé', true, $refus !== null);

$avant = $stockDe($reference);
$retour = creerDocument($session, 'RETOUR_ACHAT', ['tiers_id' => $fournisseur['id']], [[
    'article_id' => $article['id'], 'variante_id' => null, 'designation' => $article['nom'],
    'quantite' => 4, 'prix_unitaire' => num($article['prix_achat']), 'remise' => 0, 'tva' => 20,
]]);
verifier('retour accepté dans la limite', true, isset($retour['id']));
verifier('stock ressorti de 4', $avant - 4, $stockDe($reference));

echo "\n— Annulation : les mouvements sont repris —\n";
$avant = $stockDe($reference);
annulerDocument($achat['id']);
verifier('stock rendu à son état d\'avant achat', $avant - 20, $stockDe($reference));
verifier('achat annulé', 'ANNULE', valeur('SELECT statut FROM document WHERE id = ?', [$achat['id']]));

echo "\n— Calculs : remise globale au prorata et ventilation de TVA —\n";
$totaux = calculerTotaux([
    ['quantite' => 10, 'prix_unitaire' => 100, 'remise' => 0, 'tva' => 20],
    ['quantite' => 2, 'prix_unitaire' => 50, 'remise' => 10, 'tva' => 7],
], 100, 25);

verifier('total HT', 1090, $totaux['total_ht']);
verifier('base HT après remise', 990, $totaux['base_ht']);
// Valeurs relevées sur l'application d'origine : le portage doit rendre
// exactement les mêmes centimes.
verifier('TVA ventilée aux deux taux', 187.37, $totaux['total_tva']);
verifier('total TTC avec frais de port', 1202.37, $totaux['total_ttc']);

$ventilation = ventilationTVA($totaux['lignes'], $totaux['facteur']);
verifier('deux taux de TVA distincts', 2, count($ventilation));

echo "\n$reussis vérification(s) réussie(s), $echoues échec(s).\n";
exit($echoues > 0 ? 1 : 0);
