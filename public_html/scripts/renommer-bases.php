<?php
/**
 * Renomme les bases d'une installation qui portait encore l'ancien nom du
 * logiciel : `gnz_console` devient `outech_console`, `gnz_atlas` devient
 * `outech_atlas`, et le registre suit.
 *
 * Usage :
 *   php scripts/renommer-bases.php <ancien-prefixe> [--appliquer]
 *   ex. php scripts/renommer-bases.php gnz_
 *
 * Sans `--appliquer`, le script se contente d'annoncer ce qu'il ferait.
 *
 * Le déplacement se fait par `RENAME TABLE`, qui ne recopie pas les données :
 * c'est une opération de catalogue, immédiate quelle que soit la taille. Les
 * tables d'une base partent **en une seule instruction**, pour que les clés
 * étrangères qui les relient suivent le mouvement ensemble.
 */

define('RACINE', dirname(__DIR__));

require RACINE . '/app/lib/db.php';
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/saas.php';

$ancienPrefixe = $argv[1] ?? null;
$appliquer = in_array('--appliquer', $argv, true);

if ($ancienPrefixe === null || str_starts_with($ancienPrefixe, '--')) {
    exit("Usage : php scripts/renommer-bases.php <ancien-prefixe> [--appliquer]" . PHP_EOL);
}

$nouveauPrefixe = config('saas')['prefixe'] ?? 'outech_';
$consoleActuelle = baseConsole();
$ancienneConsole = $ancienPrefixe . substr($consoleActuelle, strlen($nouveauPrefixe));

/** Bases présentes sur le serveur. */
function basesExistantes(): array
{
    return dansBase('', fn() => colonne(
        'SELECT schema_name FROM information_schema.SCHEMATA ORDER BY schema_name'));
}

/** Tables d'une base. */
function tablesDe(string $base): array
{
    return dansBase('', fn() => colonne(
        'SELECT table_name FROM information_schema.TABLES WHERE table_schema = ? ORDER BY table_name',
        [$base]));
}

$existantes = basesExistantes();

// Le registre est encore sous son ancien nom : c'est là qu'on lit la liste des
// sociétés, avant de le déplacer lui aussi.
$registre = in_array($ancienneConsole, $existantes, true) ? $ancienneConsole : $consoleActuelle;

if (!in_array($registre, $existantes, true)) {
    exit("Registre introuvable (ni « $ancienneConsole », ni « $consoleActuelle »)." . PHP_EOL);
}

try {
    $societes = dansBase($registre, fn() => tous(
        "SELECT id, nom, code, base FROM saas_societe WHERE base IS NOT NULL AND base <> '' ORDER BY nom"));
} catch (Throwable $e) {
    exit('Registre illisible : ' . $e->getMessage() . PHP_EOL);
}

/* ------------------------------------------------------ ce qu'il y a à faire */

$aFaire = [];

foreach ($societes as $societe) {
    if (!str_starts_with($societe['base'], $ancienPrefixe)) continue;
    $aFaire[] = [
        'source' => $societe['base'],
        'cible'  => $nouveauPrefixe . substr($societe['base'], strlen($ancienPrefixe)),
        'quoi'   => $societe['nom'],
        'id'     => $societe['id'],
    ];
}

if ($registre === $ancienneConsole) {
    $aFaire[] = ['source' => $ancienneConsole, 'cible' => $consoleActuelle, 'quoi' => 'registre', 'id' => null];
}

// Base de repli de `config.php`, si elle porte encore l'ancien nom.
$repli = config('bd')['base'];
$ancienRepli = $ancienPrefixe . substr($repli, strlen($nouveauPrefixe));
if (str_starts_with($repli, $nouveauPrefixe)
    && in_array($ancienRepli, $existantes, true)
    && !in_array($repli, $existantes, true)) {
    $aFaire[] = ['source' => $ancienRepli, 'cible' => $repli, 'quoi' => 'base de repli', 'id' => null];
}

if (!$aFaire) exit('Rien à renommer : tout porte déjà le nom actuel.' . PHP_EOL);

/* ------------------------------------------------------------- vérifications */

$bloquants = [];

foreach ($aFaire as $etape) {
    if (!in_array($etape['source'], $existantes, true)) {
        $bloquants[] = "base absente : {$etape['source']}";
        continue;
    }
    if (!baseValide($etape['cible'])) {
        $bloquants[] = "nom de cible invalide : {$etape['cible']}";
        continue;
    }
    if (in_array($etape['cible'], $existantes, true) && tablesDe($etape['cible'])) {
        $bloquants[] = "la cible existe déjà et contient des tables : {$etape['cible']}";
    }
}

foreach ($aFaire as $etape) {
    printf("  %-34s → %-34s %s%s",
        $etape['source'], $etape['cible'],
        count(tablesDe($etape['source'])) . ' table(s) · ' . $etape['quoi'], PHP_EOL);
}

if ($bloquants) {
    echo PHP_EOL, "Rien n'a été touché :", PHP_EOL;
    foreach ($bloquants as $b) echo '  - ', $b, PHP_EOL;
    exit(1);
}

if (!$appliquer) {
    echo PHP_EOL, 'Simulation. Relancez avec --appliquer pour renommer.', PHP_EOL;
    exit(0);
}

/* ------------------------------------------------------------- le renommage */

$serveur = connexion('');
$serveur->exec('SET FOREIGN_KEY_CHECKS = 0');

foreach ($aFaire as $etape) {
    $source = $etape['source'];
    $cible = $etape['cible'];

    $serveur->exec("CREATE DATABASE IF NOT EXISTS `$cible` "
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $tables = tablesDe($source);
    if ($tables) {
        $paires = [];
        foreach ($tables as $table) $paires[] = "`$source`.`$table` TO `$cible`.`$table`";
        // Une seule instruction : les clés étrangères suivent d'un bloc.
        $serveur->exec('RENAME TABLE ' . implode(', ', $paires));
    }

    $restantes = tablesDe($source);
    if ($restantes) {
        $serveur->exec('SET FOREIGN_KEY_CHECKS = 1');
        exit("Interrompu : « $source » contient encore " . count($restantes) . " table(s)." . PHP_EOL);
    }

    $serveur->exec("DROP DATABASE `$source`");
    echo "  déplacée : $source → $cible (" . count($tables) . " tables)", PHP_EOL;
}

$serveur->exec('SET FOREIGN_KEY_CHECKS = 1');

/* --------------------------------------------- le registre pointe au bon endroit */

$misesAJour = 0;

dansConsole(function () use ($aFaire, &$misesAJour) {
    foreach ($aFaire as $etape) {
        if ($etape['id'] === null) continue;
        modifier('saas_societe', $etape['id'], ['base' => $etape['cible']]);
        $misesAJour++;
    }
});

echo PHP_EOL, count($aFaire), ' base(s) renommée(s), ', $misesAJour, ' société(s) réaiguillée(s).', PHP_EOL;
