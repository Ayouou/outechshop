<?php
/**
 * Parcours fonctionnel des consommables : déclarer du papier et du toner
 * consommés, vérifier que le stock baisse, que la valeur est figée au prix du
 * jour, et que reprendre une déclaration rend exactement ce qu'elle avait pris.
 *
 * Usage :  php scripts/parcours-consommables.php [code-societe]
 *
 * Le script écrit dans la base configurée : à réserver au développement.
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/calculs.php';
require RACINE . '/app/lib/numerotation.php';
require RACINE . '/app/lib/stock.php';
require RACINE . '/app/lib/consommables.php';

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

// `consommables.php` s'appuie sur ce helper, fourni par le routeur en HTTP.
function ouNull($valeur): ?string
{
    if ($valeur === null) return null;
    $texte = trim((string) $valeur);
    return $texte === '' ? null : $texte;
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

/* ------------------------------------------------------- deux consommables */

/** Article de test créé une seule fois, puis remis à un stock connu. */
function consommableDeTest(array $lieu, string $reference, string $nom, string $unite, float $prix, float $stock): array
{
    $article = un('SELECT * FROM article WHERE reference = ?', [$reference]);
    if (!$article) {
        inserer('article', [
            'reference' => $reference, 'nom' => $nom, 'unite' => $unite,
            'prix_achat' => $prix, 'prix_vente' => 0, 'tva' => 20,
            'gere_stock' => 1, 'actif' => 1, 'stock_alerte' => 5,
        ]);
        $article = un('SELECT * FROM article WHERE reference = ?', [$reference]);
    } else {
        modifier('article', $article['id'], ['prix_achat' => $prix]);
        $article['prix_achat'] = $prix;
    }

    q('DELETE FROM stock WHERE article_id = ? AND lieu_id = ?', [$article['id'], $lieu['id']]);
    inserer('stock', [
        'article_id' => $article['id'], 'variante_id' => null, 'lieu_id' => $lieu['id'],
        'quantite' => $stock, 'updated_at' => maintenant(),
    ]);

    return $article;
}

// Les cumuls sont vérifiés en écart : la base d'un poste de développement
// porte déjà des dépenses, et le parcours doit pouvoir se rejouer.
$cumulsAvant = cumulsDepenses('d.lieu_id = ?', [$lieu['id']]);

$papier = consommableDeTest($lieu, 'TEST-PAPIER-A4', 'Papier A4 80g (rame)', 'Rame', 45.00, 100);
$toner  = consommableDeTest($lieu, 'TEST-TONER-N', 'Toner noir', 'Cartouche', 320.00, 10);

$stockDe = fn(array $article) => num(valeur(
    'SELECT COALESCE(SUM(quantite), 0) FROM stock WHERE article_id = ? AND lieu_id = ?',
    [$article['id'], $lieu['id']],
));

verifier('papier au départ : 100 rames', 100, $stockDe($papier));
verifier('toner au départ : 10 cartouches', 10, $stockDe($toner));

/* ------------------------------------------ déclarer une consommation */

echo "\n— Déclarer 12 rames et 1 toner —\n";

$resultat = enregistrerConsommationDepense($session, [
    'designation' => 'Consommation de la semaine',
    'note'        => 'Parcours automatique',
], [
    ['article_id' => $papier['id'], 'quantite' => 12, 'prix_achat' => 45.00],
    ['article_id' => $toner['id'],  'quantite' => 1,  'prix_achat' => 320.00],
]);

verifier('consommation enregistrée', true, isset($resultat['id']));
$consoId = $resultat['id'] ?? '';

$depense = un('SELECT * FROM depense WHERE id = ?', [$consoId]);
verifier('marquée comme consommation', 'CONSOMMATION', $depense['origine']);
// 12 × 45 + 1 × 320 = 860
verifier('valorisée à 860', 860, num($depense['montant_ttc']));
verifier('sans TVA', 0, num($depense['tva']));
verifier('rien ne reste dû', 'PAYE', $depense['statut_paiement']);
verifier('numérotée en CS', 'CS', substr($depense['numero'], 0, 2));

verifier('papier retombé à 88', 88, $stockDe($papier));
verifier('toner retombé à 9', 9, $stockDe($toner));

verifier('deux mouvements de stock', 2,
    (int) valeur('SELECT COUNT(*) FROM mouvement_stock WHERE depense_id = ?', [$consoId]));
verifier('mouvements de type CONSOMMATION', 2,
    (int) valeur("SELECT COUNT(*) FROM mouvement_stock WHERE depense_id = ? AND type = 'CONSOMMATION'", [$consoId]));
verifier('sorties négatives', -13,
    num(valeur('SELECT SUM(quantite) FROM mouvement_stock WHERE depense_id = ?', [$consoId])));

$lignes = lignesConsommation($consoId);
verifier('deux lignes conservées', 2, count($lignes));
verifier('prix du papier figé à 45', 45, num($lignes[0]['prix_achat']));

/* --------------------------------------------- le prix figé ne bouge plus */

echo "\n— La fiche article change de prix : la déclaration passée ne bouge pas —\n";

modifier('article', $papier['id'], ['prix_achat' => 60.00]);

$apres = lignesConsommation($consoId);
verifier('la ligne garde son prix de 45', 45, num($apres[0]['prix_achat']));
verifier('la ligne garde sa valeur de 540', 540, num($apres[0]['total']));
verifier('le total de la dépense ne bouge pas', 860,
    num(valeur('SELECT montant_ttc FROM depense WHERE id = ?', [$consoId])));

echo "\n— Une nouvelle déclaration prend, elle, le nouveau prix —\n";

$suivante = enregistrerConsommationDepense($session, [], [
    ['article_id' => $papier['id'], 'quantite' => 2, 'prix_achat' => 0], // 0 = reprendre la fiche
]);
verifier('deuxième consommation enregistrée', true, isset($suivante['id']));
verifier('valorisée au nouveau prix : 2 × 60', 120,
    num(valeur('SELECT montant_ttc FROM depense WHERE id = ?', [$suivante['id']])));
verifier('désignation reprise du consommable', 'Consommation — Papier A4 80g (rame)',
    valeur('SELECT designation FROM depense WHERE id = ?', [$suivante['id']]));
verifier('papier retombé à 86', 86, $stockDe($papier));

/* ------------------------------------------------- les totaux restent séparés */

echo "\n— Les totaux ne mélangent pas l'achat et la consommation —\n";

$depenseSaisie = inserer('depense', [
    'numero' => numeroSuivant('DP'), 'date' => maintenant(),
    'lieu_id' => $lieu['id'], 'user_id' => $admin['id'],
    'designation' => 'Achat de 20 rames (parcours)',
    'montant_ht' => 900, 'tva' => 0, 'montant_ttc' => 900, 'montant_paye' => 900,
    'statut_paiement' => 'PAYE', 'origine' => 'SAISIE',
]);

$cumuls = cumulsDepenses('d.lieu_id = ?', [$lieu['id']]);
verifier('argent sorti : +900 (l’achat seul)', 900,
    $cumuls['saisie_ttc'] - $cumulsAvant['saisie_ttc']);
verifier('stock consommé : +980, à part', 980,
    $cumuls['conso_ttc'] - $cumulsAvant['conso_ttc']);
verifier('deux consommations de plus', 2,
    $cumuls['conso_nombre'] - $cumulsAvant['conso_nombre']);

/* --------------------------------------------------------- reprendre */

echo "\n— Reprendre une déclaration rend exactement ce qu'elle avait pris —\n";

supprimerConsommationDepense($consoId);

verifier('papier revenu à 98', 98, $stockDe($papier));
verifier('toner revenu à 10', 10, $stockDe($toner));
verifier('dépense supprimée', null, un('SELECT id FROM depense WHERE id = ?', [$consoId]));
verifier('lignes parties en cascade', 0,
    (int) valeur('SELECT COUNT(*) FROM depense_ligne WHERE depense_id = ?', [$consoId]));
verifier('mouvements effacés', 0,
    (int) valeur('SELECT COUNT(*) FROM mouvement_stock WHERE depense_id = ?', [$consoId]));

echo "\n— Une déclaration vide est refusée —\n";
$vide = enregistrerConsommationDepense($session, [], []);
verifier('refus signalé', true, isset($vide['erreur']));

$zero = enregistrerConsommationDepense($session, [], [
    ['article_id' => $papier['id'], 'quantite' => 0, 'prix_achat' => 45],
]);
verifier('quantité nulle refusée', true, isset($zero['erreur']));

/* --------------------------------------------------------------- ménage */

supprimerConsommationDepense($suivante['id']);
supprimer('depense', $depenseSaisie);
q('DELETE FROM stock WHERE article_id IN (?, ?)', [$papier['id'], $toner['id']]);
q('DELETE FROM article WHERE id IN (?, ?)', [$papier['id'], $toner['id']]);

echo "\n", str_repeat('-', 52), "\n";
echo "$reussis vérifications passées, $echoues en échec\n";
exit($echoues > 0 ? 1 : 0);
