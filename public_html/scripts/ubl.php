<?php
/**
 * Facture en UBL 2.1, sur la sortie standard.
 *
 * Sert à lire de ses yeux ce qui partira sur la plateforme, et à comparer le
 * XML à la facture imprimée avant de brancher la transmission.
 *
 * Usage :
 *   php scripts/ubl.php <code-societe> <numero>      une facture précise
 *   php scripts/ubl.php <code-societe>               la dernière facture émise
 *   php scripts/ubl.php <code-societe> <numero> > facture.xml
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/calculs.php';
require RACINE . '/app/lib/ubl.php';
require RACINE . '/app/lib/saas.php';

$code = strtolower((string) ($argv[1] ?? ''));
$numero = (string) ($argv[2] ?? '');

if ($code === '') exit("Usage : php scripts/ubl.php <code-societe> [numero]\n");

$societe = dansConsole(fn() => un('SELECT * FROM saas_societe WHERE code = ?', [$code]));
if (!$societe || !$societe['base']) exit("Aucune société installée de code « $code ».\n");
utiliserBase($societe['base']);

$document = $numero !== ''
    ? un('SELECT * FROM document WHERE numero = ?', [$numero])
    : un("SELECT * FROM document
           WHERE type IN ('FACTURE','AVOIR') AND statut <> 'ANNULE'
           ORDER BY date DESC LIMIT 1");

if (!$document) {
    exit($numero !== ''
        ? "Aucun document numéroté « $numero » dans cet espace.\n"
        : "Aucune facture dans cet espace.\n");
}

try {
    echo ublFacture($document['id']);
} catch (Throwable $e) {
    exit($e->getMessage() . "\n");
}
