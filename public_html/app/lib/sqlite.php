<?php
/**
 * Mode SQLite — pour la version de bureau (.exe), sans serveur MySQL.
 *
 * Chaque « base » devient un fichier `<nom>.sqlite` dans le dossier de données
 * (`BD_DOSSIER`). Le code de l'application reste écrit pour MySQL : les rares
 * tournures propres à MySQL sont traduites ici, au moment de la préparation,
 * par `traduireSqlite()`. Ainsi le même code sert le serveur et le bureau.
 */

/** Le pilote configuré : `mysql` (défaut) ou `sqlite`. */
function pilote(): string
{
    return strtolower((string) (config('bd')['pilote'] ?? 'mysql')) === 'sqlite' ? 'sqlite' : 'mysql';
}

function estSqlite(): bool
{
    return pilote() === 'sqlite';
}

/** Dossier des fichiers SQLite, créé au besoin. */
function dossierSqlite(): string
{
    $dossier = (string) (config('bd')['dossier'] ?? '');
    if ($dossier === '') $dossier = dirname(__DIR__, 2) . '/donnees';
    if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
        throw new RuntimeException("Dossier de données inaccessible : $dossier");
    }
    // Sur un serveur web, le dossier ne doit jamais être téléchargeable.
    if (!is_file($dossier . '/.htaccess')) {
        @file_put_contents($dossier . '/.htaccess', "Require all denied
Deny from all
");
    }
    return rtrim($dossier, '/\\');
}

/** Chemin du fichier SQLite d'une base. Le nom est vérifié avant d'arriver ici. */
function fichierSqlite(string $base): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $base)) {
        throw new RuntimeException("Nom de base invalide : « $base ».");
    }
    return dossierSqlite() . DIRECTORY_SEPARATOR . $base . '.sqlite';
}

/**
 * PDO SQLite qui traduit le SQL MySQL de l'application.
 */
class PdoSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(traduireSqlite($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(traduireSqlite($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = traduireSqlite($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/** Ouvre (et crée si besoin) la base SQLite nommée. */
function ouvrirSqlite(string $base): PDO
{
    // `$base` vide : connexion « serveur » sans base, inutile en SQLite.
    $dsn = $base === '' ? 'sqlite::memory:' : 'sqlite:' . fichierSqlite($base);

    $pdo = new PdoSqlite($dsn, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    // Fonctions MySQL encore appelées par le SQL de l'application.
    $pdo->sqliteCreateFunction('NOW', fn() => maintenant(), 0);

    return $pdo;
}

/**
 * Traduit une instruction MySQL en SQLite. Résultat mis en cache : la même
 * requête revient des centaines de fois par page.
 */
function traduireSqlite(string $sql): string
{
    static $cache = [];
    if (isset($cache[$sql])) return $cache[$sql];
    if (count($cache) > 2000) $cache = [];

    $t = ltrim($sql);

    // Réglages de session MySQL sans équivalent.
    if (preg_match('/^SET\s+(NAMES|SQL_MODE|time_zone|CHARACTER)/i', $t)) {
        return $cache[$sql] = 'SELECT 1';
    }
    if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*0/i', $t)) {
        // Dans une transaction, seul le report des contrôles est permis.
        return $cache[$sql] = 'PRAGMA defer_foreign_keys = ON';
    }
    if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i', $t)) {
        return $cache[$sql] = 'SELECT 1';
    }

    if (preg_match('/^CREATE\s+TABLE/i', $t)) {
        return $cache[$sql] = traduireCreateTable($t);
    }

    if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $t, $m)) {
        return $cache[$sql] = traduireAlterTable($m[1], trim($m[2]));
    }

    // INSERT … ON DUPLICATE KEY UPDATE x = VALUES(x)  →  UPSERT SQLite.
    if (stripos($t, 'ON DUPLICATE KEY UPDATE') !== false) {
        $t = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', 'ON CONFLICT DO UPDATE SET', $t);
        $t = preg_replace('/\bVALUES\s*\(\s*`?(\w+)`?\s*\)/i', 'excluded.$1', $t);
    }

    // GROUP_CONCAT(x ORDER BY y SEPARATOR 's')  →  GROUP_CONCAT(x, 's' ORDER BY y)
    if (stripos($t, 'GROUP_CONCAT') !== false) {
        $t = preg_replace_callback(
            '/GROUP_CONCAT\(\s*(.+?)(\s+ORDER\s+BY\s+.+?)?\s+SEPARATOR\s+(\'[^\']*\'|"[^"]*")\s*\)/is',
            function ($m) {
                $sep = "'" . str_replace("'", "''", substr($m[3], 1, -1)) . "'";
                return 'GROUP_CONCAT(' . $m[1] . ', ' . $sep . ($m[2] ?? '') . ')';
            },
            $t);
    }

    return $cache[$sql] = $t;
}

/** Type de colonne MySQL → type SQLite (avec la suite de la définition). */
function typeSqlite(string $definition): string
{
    $d = $definition;
    // Commentaires de colonne, positionnement et options de jeu de caractères.
    $d = preg_replace("/\s+COMMENT\s+'(?:[^']|'')*'/i", '', $d);
    $d = preg_replace('/\s+(AFTER\s+`?\w+`?|FIRST)\s*$/i', '', $d);
    $d = preg_replace('/\s+(CHARACTER\s+SET|CHARSET)\s+\w+/i', '', $d);
    $d = preg_replace('/\s+COLLATE\s+\w+/i', '', $d);

    $remplacements = [
        '/^ENUM\s*\((?:[^()\']|\'(?:[^\']|\'\')*\')*\)/i'            => 'TEXT',
        '/^(VAR)?CHAR\s*\(\d+\)|^(LONG|MEDIUM|TINY)?TEXT\b|^JSON\b/i' => 'TEXT COLLATE NOCASE',
        '/^(DATETIME|TIMESTAMP)(\s*\(\d+\))?|^DATE\b|^TIME\b/i'      => 'TEXT',
        '/^(TINY|SMALL|MEDIUM|BIG)?INT(EGER)?(\s*\(\d+\))?(\s+UNSIGNED)?/i' => 'INTEGER',
        '/^(DECIMAL|NUMERIC)\s*\(\d+\s*,\s*\d+\)|^(DOUBLE|FLOAT|REAL)\b/i' => 'NUMERIC',
        '/^(LONG|MEDIUM|TINY)?BLOB\b/i'                               => 'BLOB',
    ];
    foreach ($remplacements as $motif => $type) {
        $nouveau = preg_replace($motif, $type, $d, 1, $n);
        if ($n) { $d = $nouveau; break; }
    }

    return $d;
}

/** Découpe une liste séparée par des virgules, hors parenthèses et chaînes. */
function decouperDefinitions(string $corps): array
{
    $morceaux = [];
    $courant = '';
    $profondeur = 0;
    $chaine = false;
    $n = strlen($corps);
    for ($i = 0; $i < $n; $i++) {
        $c = $corps[$i];
        if ($chaine) {
            $courant .= $c;
            if ($c === "'") {
                if ($i + 1 < $n && $corps[$i + 1] === "'") { $courant .= "'"; $i++; }
                else $chaine = false;
            }
            continue;
        }
        if ($c === "'") { $chaine = true; $courant .= $c; continue; }
        if ($c === '(') $profondeur++;
        if ($c === ')') $profondeur--;
        if ($c === ',' && $profondeur === 0) {
            $morceaux[] = trim($courant);
            $courant = '';
            continue;
        }
        $courant .= $c;
    }
    if (trim($courant) !== '') $morceaux[] = trim($courant);
    return $morceaux;
}

/** CREATE TABLE MySQL → CREATE TABLE SQLite + ses CREATE INDEX. */
function traduireCreateTable(string $sql): string
{
    if (!preg_match('/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`?(\w+)`?\s*\((.*)\)[^)]*$/is', $sql, $m)) {
        return $sql;
    }
    [$_, $entete, $table, $corps] = $m;

    $colonnes = [];
    $index = [];
    $autoIncrement = false;

    foreach (decouperDefinitions($corps) as $def) {
        if (preg_match('/^PRIMARY\s+KEY/i', $def)) {
            $colonnes[] = ['pk', $def];
        } elseif (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?\w+`?\s*(\(.*\))$/is', $def, $u)) {
            $colonnes[] = ['autre', 'UNIQUE ' . $u[1]];
        } elseif (preg_match('/^(?:KEY|INDEX)\s+`?(\w+)`?\s*(\(.*\))$/is', $def, $k)) {
            $index[] = "CREATE INDEX IF NOT EXISTS `{$k[1]}` ON `$table` {$k[2]}";
        } elseif (preg_match('/^(CONSTRAINT|FOREIGN\s+KEY|CHECK)/i', $def)) {
            $colonnes[] = ['autre', $def];
        } elseif (preg_match('/^`?(\w+)`?\s+(.*)$/s', $def, $c)) {
            $type = typeSqlite($c[2]);
            if (preg_match('/\bAUTO_INCREMENT\b/i', $type)) {
                $autoIncrement = true;
                $type = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            }
            $colonnes[] = ['col', "`{$c[1]}` $type"];
        }
    }

    $lignes = [];
    foreach ($colonnes as [$genre, $texte]) {
        if ($genre === 'pk' && $autoIncrement) continue;
        $lignes[] = '  ' . $texte;
    }

    $sortie = $entete . "`$table` (\n" . implode(",\n", $lignes) . "\n)";
    foreach ($index as $creation) $sortie .= ";\n" . $creation;
    return $sortie;
}

/** ALTER TABLE MySQL → équivalent SQLite (ou rien quand c'est inutile). */
function traduireAlterTable(string $table, string $reste): string
{
    if (preg_match('/^ADD\s+COLUMN\s+`?(\w+)`?\s+(.*)$/is', $reste, $m)) {
        return "ALTER TABLE `$table` ADD COLUMN `{$m[1]}` " . typeSqlite(trim($m[2]));
    }
    if (preg_match('/^ADD\s+(?:INDEX|KEY)\s+`?(\w+)`?\s*(\(.*\))$/is', $reste, $m)) {
        return "CREATE INDEX IF NOT EXISTS `{$m[1]}` ON `$table` {$m[2]}";
    }
    // MODIFY (listes ENUM élargies) : SQLite ne contrôle pas ces valeurs.
    if (preg_match('/^(MODIFY|CHANGE)\b/i', $reste)) {
        return 'SELECT 1';
    }
    return "ALTER TABLE `$table` $reste";
}
