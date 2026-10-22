<?php
/**
 * Parcours de conformité : journal des écritures et verrou des pièces.
 *
 * Rejoue ce qu'un contrôle regarderait en premier — une facture réécrite après
 * son règlement, une facture effacée, une trace manquante — et vérifie que
 * l'application le refuse et l'inscrit.
 *
 * Usage : php scripts/parcours-conformite.php <code-societe>
 *
 * À lancer sur une société de test : le parcours crée une facture, un devis et
 * un règlement, puis laisse la facture annulée.
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/routeur.php';
require RACINE . '/app/lib/documents.php';
require RACINE . '/app/lib/calculs.php';
require RACINE . '/app/lib/numerotation.php';
require RACINE . '/app/lib/stock.php';
require RACINE . '/app/lib/ventes.php';
require RACINE . '/app/lib/facturation.php';
require RACINE . '/app/lib/retours.php';
require RACINE . '/app/lib/journal.php';
require RACINE . '/app/lib/ubl.php';
require RACINE . '/app/lib/operations.php';

require RACINE . '/app/lib/saas.php';

$code = strtolower((string) ($argv[1] ?? ''));
if ($code !== '') {
    $societe = dansConsole(fn() => un('SELECT * FROM saas_societe WHERE code = ?', [$code]));
    if (!$societe || !$societe['base']) exit("Aucune société installée de code « $code ».\n");
    utiliserBase($societe['base']);
}

$reussis = 0;
$echoues = 0;

function verifier(string $titre, $attendu, $obtenu): void
{
    global $reussis, $echoues;

    if ($attendu === $obtenu) {
        $reussis++;
        echo "✓ $titre", PHP_EOL;
        return;
    }

    $echoues++;
    echo "✗ $titre — attendu ", var_export($attendu, true),
         ', obtenu ', var_export($obtenu, true), PHP_EOL;
}

$lieu = un('SELECT * FROM lieu_affaire LIMIT 1');
$user = un("SELECT * FROM utilisateur WHERE role = 'ADMIN' LIMIT 1");
$client = un("SELECT * FROM tiers WHERE type <> 'FOURNISSEUR' LIMIT 1");
if (!$lieu || !$user || !$client) {
    exit("Espace incomplet : posez d'abord les données de démonstration.\n");
}

$session = ['lieu_id' => $lieu['id'], 'user_id' => $user['id']];
$lignes = [[
    'article_id' => null, 'variante_id' => null, 'designation' => 'Prestation (parcours conformité)',
    'quantite' => 1, 'prix_unitaire' => 1000, 'remise' => 0, 'tva' => 20,
]];

$derniereAction = fn(string $id) => valeur(
    'SELECT action FROM evenement WHERE objet_id = ? ORDER BY id DESC LIMIT 1', [$id]);

echo PHP_EOL, "— Le journal enregistre, sans qu'on le lui demande —", PHP_EOL;
$avant = (int) valeur('SELECT COUNT(*) FROM evenement');
$facture = creerDocument($session, 'FACTURE', ['tiers_id' => $client['id']], $lignes);
verifier('facture créée', true, isset($facture['id']));
verifier('création inscrite au journal', $avant + 1, (int) valeur('SELECT COUNT(*) FROM evenement'));

$evenement = un('SELECT * FROM evenement WHERE objet_id = ? ORDER BY id DESC LIMIT 1', [$facture['id']]);
verifier('action inscrite', 'creation', $evenement['action']);
verifier('auteur inscrit', $user['id'], $evenement['user_id']);
verifier("état d'arrivée conservé", true, str_contains((string) $evenement['apres'], 'FA'));

echo PHP_EOL, '— Facture sans règlement : encore modifiable —', PHP_EOL;
$resultat = modifierDocument($session, $facture['id'], ['tiers_id' => $client['id']], $lignes);
verifier('modification acceptée', false, isset($resultat['erreur']));
verifier('modification inscrite', 'modification', $derniereAction($facture['id']));

echo PHP_EOL, '— Facture réglée : figée —', PHP_EOL;
ajouterPaiement($session, $facture['id'], ['montant' => 1200, 'mode' => 'ESPECES']);
$resultat = modifierDocument($session, $facture['id'], ['tiers_id' => $client['id']], $lignes);
verifier('modification refusée', true, isset($resultat['erreur']));

echo PHP_EOL, "— Une facture ne s'efface pas —", PHP_EOL;
verifier('suppression refusée', true, supprimerDocument($facture['id'], $session) !== null);
verifier('facture toujours au dossier', 1,
    (int) valeur('SELECT COUNT(*) FROM document WHERE id = ?', [$facture['id']]));

echo PHP_EOL, '— Un devis, lui, se supprime —', PHP_EOL;
$devis = creerDocument($session, 'DEVIS', ['tiers_id' => $client['id']], $lignes);
verifier('suppression acceptée', null, supprimerDocument($devis['id'], $session));
verifier('devis parti', 0, (int) valeur('SELECT COUNT(*) FROM document WHERE id = ?', [$devis['id']]));
verifier('suppression inscrite', 'suppression', $derniereAction($devis['id']));

echo PHP_EOL, "— L'annulation garde la trace —", PHP_EOL;
annulerDocument($facture['id'], $session);
verifier('facture annulée', 'ANNULE', valeur('SELECT statut FROM document WHERE id = ?', [$facture['id']]));
verifier('annulation inscrite', 'annulation', $derniereAction($facture['id']));

echo PHP_EOL, '— Colonnes de conformité en place —', PHP_EOL;
verifier('monnaie du document', 'MAD', valeur('SELECT devise FROM document WHERE id = ?', [$facture['id']]));
verifier('pays du tiers', 'MA', valeur('SELECT pays FROM tiers WHERE id = ?', [$client['id']]));
verifier('pays de la société', 'MA', valeur('SELECT pays FROM societe LIMIT 1'));

echo PHP_EOL, '— UBL 2.1 : une facture qui ne se laisse pas arrondir —', PHP_EOL;

// Deux taux de TVA, une remise globale qui tombe mal et des frais de port :
// c'est là que la répartition au prorata et les arrondis se voient.
$compliquee = creerDocument($session, 'FACTURE', [
    'tiers_id' => $client['id'], 'remise' => 33.33, 'frais_port' => 25,
], [
    ['article_id' => null, 'variante_id' => null, 'designation' => 'Ligne à 20 %',
     'quantite' => 3, 'prix_unitaire' => 100, 'remise' => 10, 'tva' => 20],
    ['article_id' => null, 'variante_id' => null, 'designation' => 'Ligne à 7 %',
     'quantite' => 2, 'prix_unitaire' => 50, 'remise' => 0, 'tva' => 7],
]);
verifier('facture à deux taux créée', true, isset($compliquee['id']));

$piece = un('SELECT * FROM document WHERE id = ?', [$compliquee['id']]);
$xml = ublFacture($compliquee['id']);

$doc = simplexml_load_string($xml);
verifier('XML bien formé', true, $doc !== false);
verifier('racine Invoice', 'Invoice', $doc->getName());

$cbc = $doc->children('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
$cac = $doc->children('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

verifier('numéro repris', $piece['numero'], (string) $cbc->ID);
verifier('type facture (380)', '380', (string) $cbc->InvoiceTypeCode);
verifier('monnaie déclarée', 'MAD', (string) $cbc->DocumentCurrencyCode);

$pied = $cac->LegalMonetaryTotal->children(
    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
$lire = fn($noeud) => arrondir((float) (string) $noeud);

$totalHT = num($piece['total_ht']);
$remise = num($piece['remise']);
$fraisPort = num($piece['frais_port']);
$totalTva = num($piece['total_tva']);
$totalTtc = num($piece['total_ttc']);

verifier('total des lignes', $totalHT, $lire($pied->LineExtensionAmount));
verifier('remise globale reportée', $remise, $lire($pied->AllowanceTotalAmount));
verifier('frais de port reportés', $fraisPort, $lire($pied->ChargeTotalAmount));
verifier('montant hors taxe', arrondir($totalHT - $remise + $fraisPort),
    $lire($pied->TaxExclusiveAmount));
verifier('montant toutes taxes', $totalTtc, $lire($pied->TaxInclusiveAmount));
verifier('reste à payer', $totalTtc, $lire($pied->PayableAmount));

// La norme veut que chaque somme se retrouve : bases et taxes des sous-totaux
// doivent redonner, au centime, le hors taxe et la TVA du pied.
$taxTotal = $cac->TaxTotal->children(
    'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
verifier('TVA totale', $totalTva, $lire($taxTotal->TaxAmount));

$bases = 0.0;
$taxes = 0.0;
foreach ($cac->TaxTotal->children(
    'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2')->TaxSubtotal as $sous) {
    $c = $sous->children('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $bases += (float) (string) $c->TaxableAmount;
    $taxes += (float) (string) $c->TaxAmount;
}
verifier('somme des bases = hors taxe', arrondir($totalHT - $remise + $fraisPort), arrondir($bases));
verifier('somme des taxes = TVA totale', $totalTva, arrondir($taxes));

$lignesXml = $cac->InvoiceLine;
verifier('deux lignes dans le XML', 2, count($lignesXml));

$sommeLignes = 0.0;
foreach ($lignesXml as $ligneXml) {
    $c = $ligneXml->children('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $sommeLignes += (float) (string) $c->LineExtensionAmount;
}
verifier('lignes du XML = total des lignes', $totalHT, arrondir($sommeLignes));

// La remise globale est éclatée par taux : un seul bloc pour deux taux
// déséquilibrerait les catégories.
$remises = 0.0;
$charges = 0.0;
foreach ($cac->AllowanceCharge as $ajustement) {
    $c = $ajustement->children('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    if ((string) $c->ChargeIndicator === 'true') $charges += (float) (string) $c->Amount;
    else $remises += (float) (string) $c->Amount;
}
verifier('remises réparties = remise globale', $remise, arrondir($remises));
verifier('charges = frais de port', $fraisPort, arrondir($charges));

$refus = null;
try {
    $devisUbl = creerDocument($session, 'DEVIS', ['tiers_id' => $client['id']], $lignes);
    ublFacture($devisUbl['id']);
} catch (RuntimeException $e) {
    $refus = 'refusé';
}
verifier('un devis ne se met pas en UBL', 'refusé', $refus);

annulerDocument($compliquee['id'], $session);

echo PHP_EOL, "$reussis réussi(s), $echoues échec(s)", PHP_EOL;
exit($echoues > 0 ? 1 : 0);
