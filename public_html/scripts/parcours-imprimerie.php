<?php
/**
 * Parcours fonctionnel de l'atelier : ordre de travail, avancement, sortie de
 * matière, chute, reprise et facturation — avec vérification du stock à
 * chaque étape.
 *
 * Usage :  php scripts/parcours-imprimerie.php [code-societe]
 *
 * Le script écrit dans la base configurée, puis efface ce qu'il a créé :
 * à réserver au développement.
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/documents.php';
require RACINE . '/app/lib/calculs.php';
require RACINE . '/app/lib/numerotation.php';
require RACINE . '/app/lib/stock.php';
require RACINE . '/app/lib/imprimerie.php';
require RACINE . '/app/lib/ventes.php';
require RACINE . '/app/lib/facturation.php';
require RACINE . '/app/lib/retours.php';
require RACINE . '/app/lib/journal.php';
require RACINE . '/app/lib/operations.php';

require RACINE . '/app/lib/saas.php';

$code = $argv[1] ?? null;
if ($code !== null) {
    $societe = dansConsole(fn() => un('SELECT * FROM saas_societe WHERE code = ?', [strtolower($code)]));
    if (!$societe || empty($societe['base'])) exit("Aucun espace installé pour le code « $code »." . PHP_EOL);
    utiliserBase($societe['base']);
    echo "Base : {$societe['base']} ({$societe['nom']})" . PHP_EOL;
}

// Helpers fournis par le routeur en HTTP.
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
    $ok = is_numeric($attendu) && is_numeric($obtenu)
        ? abs((float) $attendu - (float) $obtenu) < 0.005
        : $attendu === $obtenu;

    if ($ok) { $reussis++; echo "✓ $libelle\n"; return; }
    $echoues++;
    echo "✗ $libelle — attendu " . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true) . "\n";
}

/* ------------------------------------------------ matière et machine d'essai */

$support = un('SELECT * FROM article WHERE gere_stock = 1 AND actif = 1 ORDER BY created_at LIMIT 1');
if (!$support) exit("Aucun article suivi en stock : lancez d'abord scripts/donnees-demo.php.\n");

$stockDe = fn() => num(valeur(
    'SELECT COALESCE(SUM(quantite), 0) FROM stock WHERE article_id = ? AND lieu_id = ?',
    [$support['id'], $lieu['id']],
));

$machineId = valeur('SELECT id FROM machine ORDER BY created_at LIMIT 1');
$stockDepart = $stockDe();

echo PHP_EOL, "Matière d'essai : {$support['nom']} (stock {$stockDepart})", PHP_EOL, PHP_EOL;

/* ------------------------------------------------------ 1. ordre de travail */

echo "1. Création de l'ordre de travail", PHP_EOL;

$resultat = enregistrerTravail($session, null, [
    'designation'  => 'Parcours automatique — bâche + DTF',
    'date'         => maintenant(),
    'date_promise' => (new DateTime('+2 days'))->format('Y-m-d H:i:s.v'),
    'priorite'     => 'URGENTE',
    'machine_id'   => $machineId,
    'avance'       => 100,
], [
    // 3 × 2 m, un exemplaire, 60 dh le m² : 6 m² → 360 HT, 432 TTC.
    ['designation' => 'Bâche quadri', 'mode' => 'M2', 'largeur' => 3, 'hauteur' => 2,
     'quantite' => 1, 'prix_unitaire' => 60, 'remise' => 0, 'tva' => 20, 'unites' => 6],
    // 10 mètres de film à 35 dh : 350 HT.
    ['designation' => 'Film DTF au mètre', 'mode' => 'ML', 'largeur' => 0, 'hauteur' => 10,
     'quantite' => 1, 'prix_unitaire' => 35, 'remise' => 0, 'tva' => 20, 'unites' => 10],
]);

if (isset($resultat['erreur'])) exit("Création refusée : {$resultat['erreur']}\n");
$travailId = $resultat['id'];
$travail = un('SELECT * FROM travail WHERE id = ?', [$travailId]);

verifier('Étape initiale', 'ACCUEIL', $travail['etape']);
verifier('Total HT', 710, $travail['total_ht']);
verifier('TVA', 142, $travail['total_tva']);
verifier('Total TTC', 852, $travail['total_ttc']);
verifier('Entrée journalisée', 1, valeur('SELECT COUNT(*) FROM travail_etape WHERE travail_id = ?', [$travailId]));

$lignes = tous('SELECT * FROM travail_ligne WHERE travail_id = ? ORDER BY ordre', [$travailId]);
verifier('Surface calculée (m²)', 6, $lignes[0]['unites']);
verifier('Longueur calculée (m)', 10, $lignes[1]['unites']);

/* --------------------------------------------------------- 2. avancement */

echo PHP_EOL, "2. Avancement dans l'atelier", PHP_EOL;

changerEtape($session, $travailId, 'DESIGN', 'Maquette lancée');
changerEtape($session, $travailId, 'IMPRESSION');

$travail = un('SELECT * FROM travail WHERE id = ?', [$travailId]);
verifier('Étape courante', 'IMPRESSION', $travail['etape']);
verifier('Designer retenu', $admin['id'], $travail['designer_id']);
verifier('Machiniste retenu', $admin['id'], $travail['machiniste_id']);
verifier('Passages journalisés', 3, valeur('SELECT COUNT(*) FROM travail_etape WHERE travail_id = ?', [$travailId]));
verifier('Étape suivante proposée', 'FINITION', etapeSuivante($travail['etape']));

/* ------------------------------------------------------- 3. sortie matière */

echo PHP_EOL, '3. Sortie de matière (utilisée + chute)', PHP_EOL;

$sortie = enregistrerConsommation($session, [
    'travail_id' => $travailId,
    'article_id' => $support['id'],
    'machine_id' => $machineId,
    'quantite'   => 5,
    'chute'      => 1.5,
    'motif'      => 'ERREUR_IMPRESSION',
    'note'       => 'Parcours automatique',
]);
if (isset($sortie['erreur'])) exit("Sortie refusée : {$sortie['erreur']}\n");

verifier('Stock diminué du total sorti', $stockDepart - 6.5, $stockDe());
verifier('Mouvement de production', 1, valeur(
    "SELECT COUNT(*) FROM mouvement_stock WHERE consommation_id = ? AND type = 'PRODUCTION'", [$sortie['id']]));
verifier('Mouvement de chute', 1, valeur(
    "SELECT COUNT(*) FROM mouvement_stock WHERE consommation_id = ? AND type = 'CHUTE'", [$sortie['id']]));

$bilan = bilanMatiere($travailId);
verifier('Bilan — utilisée', 5, $bilan['utilisee']);
verifier('Bilan — chute', 1.5, $bilan['chute']);
verifier('Taux de chute', 23.08, tauxChute($bilan['utilisee'], $bilan['chute']));

/* --------------------------------------------------------- 4. facturation */

echo PHP_EOL, '4. Facturation du travail', PHP_EOL;

$stockAvantFacture = $stockDe();
$facture = facturerTravail($session, $travailId, 'FACTURE');
if (isset($facture['erreur'])) exit("Facturation refusée : {$facture['erreur']}\n");

$document = un('SELECT * FROM document WHERE id = ?', [$facture['id']]);
verifier('Facture au même total', $travail['total_ttc'], $document['total_ttc']);
verifier('Avance reportée', 100, $document['montant_paye']);
verifier('Travail lié à la pièce', $facture['id'],
    valeur('SELECT document_id FROM travail WHERE id = ?', [$travailId]));
// La matière est déjà sortie par l'atelier : la facture ne doit pas la ressortir.
verifier('Stock inchangé par la facture', $stockAvantFacture, $stockDe());

$refus = facturerTravail($session, $travailId, 'FACTURE');
verifier('Seconde facturation refusée', true, isset($refus['erreur']));

/* ------------------------------------ 4bis. sortie automatique de la matière */

echo PHP_EOL, '4bis. Catalogue et sortie automatique', PHP_EOL;

// Un rouleau de 1,50 m de laize, et la prestation qui le consomme.
$rouleauId = valeur('SELECT id FROM article WHERE reference = ?', ['PARCOURS-SUP'])
    ?: inserer('article', [
        'reference' => 'PARCOURS-SUP', 'nom' => 'Support de parcours', 'unite' => 'Mètre',
        'laize' => 1.5, 'type_impression' => 'SUPPORT', 'prix_achat' => 10, 'prix_vente' => 30,
        'tva' => 20, 'gere_stock' => 1,
    ]);

$prestationId = valeur('SELECT id FROM article WHERE reference = ?', ['PARCOURS-PRE'])
    ?: inserer('article', [
        'reference' => 'PARCOURS-PRE', 'nom' => 'Impression de parcours', 'unite' => 'Mètre carré',
        'type_impression' => 'PRESTATION', 'support_id' => $rouleauId, 'conso_unitaire' => 0,
        'prix_vente' => 50, 'tva' => 20, 'gere_stock' => 0,
    ]);

$stockRouleau = fn() => num(valeur(
    'SELECT COALESCE(SUM(quantite), 0) FROM stock WHERE article_id = ? AND lieu_id = ?',
    [$rouleauId, $lieu['id']],
));

appliquerMouvement([
    'article_id' => $rouleauId, 'lieu_id' => $lieu['id'], 'type' => 'AJUSTEMENT',
    'quantite' => 100, 'note' => 'Parcours', 'user_id' => $admin['id'],
]);
$avantRouleau = $stockRouleau();

// 2,40 m de large sur un rouleau de 1,50 m : deux lés, donc 2 × 3 m = 6 m.
$auto = enregistrerTravail($session, null, [
    'designation' => 'Parcours automatique — sortie matière', 'date' => maintenant(),
], [
    ['article_id' => $prestationId, 'designation' => 'Bâche large', 'mode' => 'M2',
     'largeur' => 2.4, 'hauteur' => 3, 'quantite' => 1, 'prix_unitaire' => 50, 'tva' => 20],
]);
if (isset($auto['erreur'])) exit("Création refusée : {$auto['erreur']}
");

$ligneAuto = un('SELECT * FROM travail_ligne WHERE travail_id = ?', [$auto['id']]);
verifier('Support repris du catalogue', $rouleauId, $ligneAuto['support_id']);
verifier('Deux lés comptés (2,4 m sur laize 1,5 m)', 6, $ligneAuto['conso_prevue']);
verifier('Rien sorti avant impression', $avantRouleau, $stockRouleau());

changerEtape($session, $auto['id'], 'DESIGN');
verifier('Toujours rien au design', $avantRouleau, $stockRouleau());

$passage = changerEtape($session, $auto['id'], 'IMPRESSION');
verifier('Une sortie créée', 1, $passage['sorties'] ?? 0);
verifier('Rouleau entamé de 6 m', $avantRouleau - 6, $stockRouleau());

// Repasser par l'étape ne doit pas reprélever.
changerEtape($session, $auto['id'], 'FINITION');
changerEtape($session, $auto['id'], 'IMPRESSION');
verifier('Pas de second prélèvement', $avantRouleau - 6, $stockRouleau());

supprimerTravail($auto['id']);
verifier('Matière rendue avec le dossier', $avantRouleau, $stockRouleau());

/* ------------------------------------------------------ 5. annulation client */

echo PHP_EOL, "5. Annulation par le client", PHP_EOL;

$refus = changerEtape($session, $travailId, 'ANNULE', '   ');
verifier('Annulation sans motif refusée', true, isset($refus['erreur']));
verifier('Étape inchangée après le refus', 'IMPRESSION',
    valeur('SELECT etape FROM travail WHERE id = ?', [$travailId]));

changerEtape($session, $travailId, 'ANNULE', "Le client a changé d'avis");
$annule = un('SELECT etape, motif_annulation FROM travail WHERE id = ?', [$travailId]);
verifier('Travail annulé', 'ANNULE', $annule['etape']);
verifier('Motif enregistré', "Le client a changé d'avis", $annule['motif_annulation']);
verifier('Annulation journalisée', 1, valeur(
    "SELECT COUNT(*) FROM travail_etape WHERE travail_id = ? AND etape = 'ANNULE'", [$travailId]));

changerEtape($session, $travailId, 'ACCUEIL', 'Le client revient');
$rouvert = un('SELECT etape, motif_annulation FROM travail WHERE id = ?', [$travailId]);
verifier('Dossier rouvert', 'ACCUEIL', $rouvert['etape']);
verifier('Motif effacé à la réouverture', null, $rouvert['motif_annulation']);

/* ------------------------------------------------------------ 6. reprise */

echo PHP_EOL, '6. Reprise de la sortie de matière', PHP_EOL;

supprimerConsommation($sortie['id']);
verifier('Stock rendu', $stockDepart, $stockDe());
verifier('Mouvements effacés', 0,
    valeur('SELECT COUNT(*) FROM mouvement_stock WHERE consommation_id = ?', [$sortie['id']]));

/* ------------------------------------------------------------- 7. ménage */

echo PHP_EOL, '7. Suppression du dossier', PHP_EOL;

// Une sortie restée en place doit revenir au stock avec le dossier.
$derniere = enregistrerConsommation($session, [
    'travail_id' => $travailId, 'article_id' => $support['id'], 'quantite' => 2, 'chute' => 0,
]);
verifier('Stock repris pour le test', $stockDepart - 2, $stockDe());

supprimerTravail($travailId);
verifier('Dossier supprimé', 0, valeur('SELECT COUNT(*) FROM travail WHERE id = ?', [$travailId]));
verifier('Lignes supprimées', 0, valeur('SELECT COUNT(*) FROM travail_ligne WHERE travail_id = ?', [$travailId]));
verifier('Journal supprimé', 0, valeur('SELECT COUNT(*) FROM travail_etape WHERE travail_id = ?', [$travailId]));
verifier('Matière rendue avec le dossier', $stockDepart, $stockDe());

// Une facture ne s'efface pas : le parcours la laisse annulée, comme en vrai.
annulerDocument($facture['id']);

echo PHP_EOL, "$reussis réussi(s), $echoues échec(s)", PHP_EOL;
exit($echoues > 0 ? 1 : 0);
