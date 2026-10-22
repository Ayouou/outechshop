<?php
/**
 * Accès à la base : connexion PDO unique et helpers de requête.
 *
 * Tout passe par des requêtes préparées — aucune valeur n'est concaténée dans
 * le SQL. Les identifiants sont des chaînes façon cuid, générées ici, pour
 * rester compatibles avec les sauvegardes de l'application d'origine.
 *
 * Deux pilotes : MySQL (serveur) et SQLite (version de bureau, voir sqlite.php).
 */

require_once __DIR__ . '/sqlite.php';

function config(?string $cle = null)
{
    static $config = null;
    if ($config === null) {
        $chemin = RACINE . '/config.php';
        if (!is_file($chemin)) {
            exit('Configuration absente : le fichier config.php manque à la racine.');
        }
        $config = require $chemin;
    }
    return $cle === null ? $config : ($config[$cle] ?? null);
}

/**
 * Base active de la requête HTTP.
 *
 * Une installation OUTECH héberge plusieurs sociétés : la console tient son
 * registre dans sa propre base, et chaque société a la sienne. `bd()` renvoie
 * toujours la connexion de la base active, posée en début de requête par
 * `contexteRequete()` (voir `app/lib/saas.php`).
 */
function baseActive(): string
{
    return $GLOBALS['outech_base_active'] ?? config('bd')['base'];
}

/** Bascule la base active. Les connexions déjà ouvertes sont réutilisées. */
function utiliserBase(string $base): void
{
    $GLOBALS['outech_base_active'] = $base;
}

/**
 * Exécute une fonction dans une autre base, puis rétablit la précédente.
 * C'est ainsi que la console lit les données d'une société sans jamais
 * mélanger deux bases dans une même requête.
 */
function dansBase(string $base, callable $fn)
{
    $precedente = baseActive();
    utiliserBase($base);
    try {
        return $fn();
    } finally {
        utiliserBase($precedente);
    }
}

/** Connexion PDO de la base active. */
function bd(): PDO
{
    return connexion(baseActive());
}

/**
 * Connexion PDO à une base nommée, ouverte une seule fois par requête.
 *
 * L'échec lève une exception plutôt que d'arrêter le script : une base absente
 * est une situation ordinaire ici (registre pas encore installé, espace d'une
 * société à créer), et l'appelant sait souvent quoi en faire. Ce qui remonte
 * jusqu'en haut est présenté par le gestionnaire d'`index.php`.
 *
 * @throws RuntimeException
 */
function connexion(string $base): PDO
{
    static $connexions = [];
    if (isset($connexions[$base])) return $connexions[$base];

    try {
        $pdo = ouvrirPdo($base);
    } catch (PDOException $e) {
        throw new RuntimeException(
            'Connexion à la base « ' . $base . ' » impossible : ' . $e->getMessage(), 0, $e);
    }

    return $connexions[$base] = $pdo;
}

/**
 * Ouvre une connexion PDO. `$base` vide connecte au serveur sans sélectionner
 * de base — c'est ce dont on a besoin pour créer celle d'une nouvelle société.
 *
 * @throws PDOException
 */
function ouvrirPdo(string $base): PDO
{
    if (estSqlite()) return ouvrirSqlite($base);

    $c = config('bd');
    $cible = $base === '' ? '' : "dbname={$base};";
    $dsn = $c['socket']
        ? "mysql:unix_socket={$c['socket']};{$cible}charset=utf8mb4"
        : "mysql:host={$c['hote']};port={$c['port']};{$cible}charset=utf8mb4";

    $pdo = new PDO($dsn, $c['utilisateur'], $c['motdepasse'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Les requêtes préparées côté serveur MySQL : les entiers restent
        // des entiers dans les LIMIT et les décimaux ne sont pas retouchés.
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    // Un fuseau serveur différent de celui de PHP décalerait les dates écrites
    // par MySQL (NOW(), CURRENT_TIMESTAMP) ; on aligne les deux.
    $decalage = (new DateTime('now'))->format('P');
    $pdo->exec("SET time_zone = '$decalage'");

    return $pdo;
}

/** Exécute une requête préparée et renvoie le statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = bd()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Toutes les lignes. */
function tous(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Première ligne, ou null. */
function un(string $sql, array $params = []): ?array
{
    $ligne = q($sql, $params)->fetch();
    return $ligne === false ? null : $ligne;
}

/** Première colonne de la première ligne. */
function valeur(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Liste de la première colonne. */
function colonne(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Identifiant façon cuid : `c` + horodatage base36 + compteur + aléa.
 * 25 caractères, comme ceux de l'application d'origine.
 */
function cuid(): string
{
    static $compteur = 0;
    $compteur = ($compteur + 1) % 1679616; // 36^4
    $temps = base_convert((string) (int) (microtime(true) * 1000), 10, 36);
    $bloc  = str_pad(base_convert((string) $compteur, 10, 36), 4, '0', STR_PAD_LEFT);
    $alea  = substr(bin2hex(random_bytes(8)), 0, 8);
    return substr('c' . str_pad($temps, 8, '0', STR_PAD_LEFT) . $bloc . $alea, 0, 25);
}

/** Horodatage au format MySQL DATETIME(3). */
function maintenant(): string
{
    return (new DateTime())->format('Y-m-d H:i:s.v');
}

/** Convertit une date (string|DateTime|null) en DATETIME(3) MySQL, ou null. */
function horodatage($valeur): ?string
{
    if ($valeur === null || $valeur === '') return null;
    if ($valeur instanceof DateTimeInterface) return $valeur->format('Y-m-d H:i:s.v');
    try {
        return (new DateTime((string) $valeur))->format('Y-m-d H:i:s.v');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Insère une ligne et renvoie son identifiant.
 * `id`, `created_at` et `updated_at` sont remplis s'ils manquent et si la
 * table les porte (voir `colonnesTable`).
 */
function inserer(string $table, array $donnees): string
{
    $colonnes = colonnesTable($table);

    if (isset($colonnes['id']) && empty($donnees['id'])) $donnees['id'] = cuid();
    $now = maintenant();
    if (isset($colonnes['created_at']) && !isset($donnees['created_at'])) $donnees['created_at'] = $now;
    if (isset($colonnes['updated_at']) && !isset($donnees['updated_at'])) $donnees['updated_at'] = $now;

    $donnees = array_intersect_key($donnees, $colonnes);

    $champs = array_keys($donnees);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $champs) . '`) VALUES ('
        . implode(', ', array_fill(0, count($champs), '?')) . ')';

    q($sql, array_values(array_map('valeurSql', $donnees)));

    return (string) ($donnees['id'] ?? bd()->lastInsertId());
}

/** Insère plusieurs lignes d'un coup (mêmes colonnes pour toutes). */
function insererLot(string $table, array $lignes): void
{
    if (!$lignes) return;
    $colonnes = colonnesTable($table);
    $now = maintenant();

    $preparees = [];
    foreach ($lignes as $ligne) {
        if (isset($colonnes['id']) && empty($ligne['id'])) $ligne['id'] = cuid();
        if (isset($colonnes['created_at']) && !isset($ligne['created_at'])) $ligne['created_at'] = $now;
        if (isset($colonnes['updated_at']) && !isset($ligne['updated_at'])) $ligne['updated_at'] = $now;
        $preparees[] = array_intersect_key($ligne, $colonnes);
    }

    $champs = array_keys($preparees[0]);
    $marqueurs = '(' . implode(', ', array_fill(0, count($champs), '?')) . ')';

    // MySQL limite le nombre de paramètres liés (65535) : on écrit par paquets.
    $parPaquet = max(1, (int) floor(60000 / max(1, count($champs))));

    foreach (array_chunk($preparees, $parPaquet) as $paquet) {
        $valeurs = [];
        foreach ($paquet as $ligne) {
            foreach ($champs as $champ) $valeurs[] = valeurSql($ligne[$champ] ?? null);
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $champs) . '`) VALUES '
            . implode(', ', array_fill(0, count($paquet), $marqueurs));
        q($sql, $valeurs);
    }
}

/** Met à jour une ligne par identifiant. */
function modifier(string $table, string $id, array $donnees): void
{
    $colonnes = colonnesTable($table);
    if (isset($colonnes['updated_at']) && !isset($donnees['updated_at'])) {
        $donnees['updated_at'] = maintenant();
    }
    $donnees = array_intersect_key($donnees, $colonnes);
    unset($donnees['id']);
    if (!$donnees) return;

    $sets = [];
    $valeurs = [];
    foreach ($donnees as $champ => $valeur) {
        $sets[] = "`$champ` = ?";
        $valeurs[] = valeurSql($valeur);
    }
    $valeurs[] = $id;

    q('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `id` = ?', $valeurs);
}

function supprimer(string $table, string $id): void
{
    q('DELETE FROM `' . $table . '` WHERE `id` = ?', [$id]);
}

/** Normalise une valeur PHP pour PDO (bool → 0/1, DateTime → chaîne). */
function valeurSql($valeur)
{
    if (is_bool($valeur)) return $valeur ? 1 : 0;
    if ($valeur instanceof DateTimeInterface) return $valeur->format('Y-m-d H:i:s.v');
    return $valeur;
}

/** Colonnes d'une table, en cache pour la durée de la requête HTTP. */
function colonnesTable(string $table): array
{
    static $cache = [];
    // La clé porte la base : la table `document` de deux sociétés n'est pas la
    // même table, même si son schéma est identique.
    $cle = baseActive() . '.' . $table;
    if (isset($cache[$cle])) return $cache[$cle];

    $colonnes = [];
    $nom = str_replace('`', '', $table);
    if (estSqlite()) {
        foreach (tous('PRAGMA table_info(`' . $nom . '`)') as $c) $colonnes[$c['name']] = true;
    } else {
        foreach (tous('SHOW COLUMNS FROM `' . $nom . '`') as $c) $colonnes[$c['Field']] = true;
    }
    return $cache[$cle] = $colonnes;
}

/** La table existe-t-elle dans la base active (ou la connexion donnée) ? */
function tableExiste(string $table, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?? bd();
    $sql = estSqlite()
        ? "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        : 'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?';
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

/** La colonne existe-t-elle ? */
function colonneExiste(PDO $pdo, string $table, string $colonne): bool
{
    if (estSqlite()) {
        foreach ($pdo->query('PRAGMA table_info(`' . str_replace('`', '', $table) . '`)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (strcasecmp($c['name'], $colonne) === 0) return true;
        }
        return false;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute([$table, $colonne]);
    return (int) $st->fetchColumn() > 0;
}

/** L'index existe-t-il ? */
function indexExiste(PDO $pdo, string $table, string $index): bool
{
    if (estSqlite()) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$index]);
        return (int) $st->fetchColumn() > 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $st->execute([$table, $index]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Exécute une fonction dans une transaction.
 * Toute exception annule l'ensemble — la base reste dans son état d'origine.
 */
function transaction(callable $fn)
{
    $pdo = bd();
    if ($pdo->inTransaction()) return $fn($pdo);

    $pdo->beginTransaction();
    try {
        $resultat = $fn($pdo);
        $pdo->commit();
        return $resultat;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Construit une clause `IN (?, ?, …)` et complète le tableau de paramètres.
 * Renvoie `NULL` (jamais vrai) pour une liste vide, ce qui évite un SQL invalide.
 */
function clauseIn(array $valeurs, array &$params): string
{
    if (!$valeurs) return 'NULL';
    foreach ($valeurs as $valeur) $params[] = $valeur;
    return implode(', ', array_fill(0, count($valeurs), '?'));
}
