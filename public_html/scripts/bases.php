<?php
/**
 * Bases des sociétés — état et mise à jour du schéma.
 *
 * Chaque société ayant sa propre base, une évolution de `install/outech.sql` doit
 * être appliquée à toutes. Ce script les parcourt et rejoue le schéma
 * (`CREATE TABLE IF NOT EXISTS`) : les bases déjà à jour ne bougent pas.
 *
 * Usage :
 *   php scripts/bases.php              état de chaque société
 *   php scripts/bases.php --migrer     applique install/outech.sql à toutes
 *   php scripts/bases.php --migrer <code>   à une seule société
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/saas.php';

$migrer = in_array('--migrer', $argv, true);
$cible = null;
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) $cible = strtolower($arg);
}

// Le registre évolue lui aussi : on rejoue son schéma avant tout le reste.
if ($migrer) {
    try {
        installerConsole();
        echo 'Registre : schéma à jour.', PHP_EOL;
    } catch (Throwable $e) {
        exit('Registre : ' . $e->getMessage() . PHP_EOL);
    }
}

try {
    $societes = dansConsole(fn() => tous(
        "SELECT * FROM saas_societe WHERE base IS NOT NULL AND base <> '' ORDER BY nom"));
} catch (Throwable $e) {
    exit("Registre injoignable : " . $e->getMessage() . "\n"
        . "Vérifiez 'saas' => 'console' dans config.php, puis importez install/console.sql.\n");
}

if ($cible !== null) {
    $societes = array_values(array_filter($societes, fn($s) => $s['code'] === $cible));
    if (!$societes) exit("Aucune société de code « $cible » avec un espace installé.\n");
}

if (!$societes) exit("Aucun espace installé.\n");

$echecs = 0;

foreach ($societes as $societe) {
    // Le code porte 24 chiffres : en colonne, sa forme abrégée suffit.
    // `str_pad` compte les octets et le « … » en pèse trois : on aligne sur
    // les caractères, sinon la colonne se décale.
    $code = codeCourt($societe['code']);
    $ligne = $code . str_repeat(' ', max(1, 26 - mb_strlen($code))) . str_pad($societe['base'], 26);

    try {
        if ($migrer) {
            appliquerSchema($societe['base']);
            $ligne .= 'schéma appliqué   ';

            // Les adresses publiques vivent dans le registre, commun à toutes
            // les sociétés : on y inscrit les catalogues qui existaient avant.
            $catalogues = dansBase($societe['base'], fn() => tous('SELECT slug, lieu_id FROM catalogue'));
            foreach ($catalogues as $catalogue) {
                if (!adresseDisponible($catalogue['slug'], $societe['id'])) continue;
                reserverAdressePublique($catalogue['slug'], $societe['id'], $catalogue['lieu_id']);
                $ligne .= '/c/' . $catalogue['slug'] . '   ';
            }
        }

        $stats = dansBase($societe['base'], fn() => [
            'tables'    => (int) valeur('SELECT COUNT(*) FROM information_schema.TABLES
                                          WHERE table_schema = DATABASE()'),
            'documents' => (int) valeur('SELECT COUNT(*) FROM document'),
        ]);

        $ligne .= $stats['tables'] . ' tables · ' . $stats['documents'] . ' documents'
            . ' · ' . situation($societe);
    } catch (Throwable $e) {
        $echecs++;
        $ligne .= 'ÉCHEC : ' . $e->getMessage();
    }

    echo $ligne, "\n";
}

echo "\n", count($societes), " société(s), $echecs échec(s).\n";
exit($echecs > 0 ? 1 : 0);
