<?php
/**
 * Lecture du fichier `.env`.
 *
 * Les identifiants de connexion changent d'une machine à l'autre : poste de
 * développement, hébergement mutualisé, éventuel serveur de recette. Les
 * garder dans `config.php` obligeait à modifier un fichier de code à chaque
 * déploiement — et à risquer d'écraser les identifiants du serveur avec ceux
 * du poste local. Ils vivent désormais dans un fichier de valeurs, hors du
 * code, refusé par le serveur web (voir `.htaccess`).
 *
 * Deux fichiers sont lus, dans cet ordre :
 *   1. `.env`        — celui qu'on téléverse chez l'hébergeur ;
 *   2. `.env.local`  — propre au poste, il l'emporte là où il s'applique.
 *
 * Le second n'est pas censé être téléversé : c'est ce qui permet de garder sur
 * le poste de développement une configuration locale sans toucher à celle de
 * production. Comme il part quand même avec le dossier quand on téléverse tout,
 * il n'est lu que si la machine est bien un poste local — voir `posteLocal()`.
 */

/**
 * Toutes les valeurs des fichiers `.env`, lues une seule fois.
 *
 * La racine est déduite de l'emplacement de ce fichier plutôt que de la
 * constante `RACINE` : `config.php` est aussi chargé par des scripts en ligne
 * de commande, qui ne la définissent pas tous.
 */
function envTout(): array
{
    static $valeurs = null;
    if ($valeurs !== null) return $valeurs;

    $racine = dirname(__DIR__, 2);

    $valeurs = lireEnv($racine . '/.env');

    // `.env.local` ne se lit que sur un poste de développement. Il part avec
    // le reste du dossier quand on téléverse, et chez l’hébergeur il écrase
    // les identifiants du panneau par ceux du poste — `root`, sans mot de
    // passe — que MySQL refuse aussitôt par un « Access denied for user
    // root@localhost ». Le garde-fou tient ici plutôt que dans une consigne de
    // déploiement : une consigne s’oublie, ce test non.
    if (posteLocal()) {
        $valeurs = array_merge($valeurs, lireEnv($racine . '/.env.local'));
    }

    return $valeurs;
}

/**
 * La machine qui exécute ce code est-elle un poste de développement ?
 *
 * En ligne de commande, oui : ce sont les scripts de `scripts/`, qu’on ne
 * lance pas chez l’hébergeur. Sous `php -S` aussi : ce serveur-là n’est fait
 * que pour le développement, et il ne renseigne même pas `SERVER_ADDR`.
 *
 * En HTTP, on regarde l’adresse du serveur — jamais l’en-tête `Host`, qui
 * vient du client et se falsifie. Une adresse publique désigne un hébergement ;
 * le développement se fait sur la boucle locale (127.0.0.1, ::1) ou dans un
 * réseau privé (192.168.x, 10.x), justement les plages que les deux drapeaux
 * ci-dessous écartent. Adresse inconnue : on tranche pour l’hébergeur, le côté
 * où se tromper coûte le moins cher.
 */
function posteLocal(): bool
{
    // En ligne de commande comme sous le serveur intégré de PHP (`php -S`,
    // voir `router.php`), on est sur un poste : le premier sert aux scripts
    // de `scripts/`, le second ne tourne que pendant le développement.
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') return true;

    $adresse = $_SERVER['SERVER_ADDR'] ?? '';
    if ($adresse === '') return false;

    return filter_var($adresse, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Valeur d'une clé, avec conversion des littéraux usuels.
 * `true`/`false` deviennent des booléens, un nombre entier devient un entier —
 * `DEBOGAGE=false` doit être faux, pas la chaîne « false », qui serait vraie.
 */
function env(string $cle, $defaut = null)
{
    // Une variable d'environnement du processus l'emporte sur les fichiers :
    // c'est ainsi que la version de bureau (.exe) impose SQLite et son dossier.
    $systeme = getenv($cle);
    if ($systeme !== false && $systeme !== '') {
        $valeur = $systeme;
    } else {
        $valeurs = envTout();
        if (!array_key_exists($cle, $valeurs)) return $defaut;
        $valeur = $valeurs[$cle];
    }

    return match (strtolower($valeur)) {
        'true', 'oui', 'on'   => true,
        'false', 'non', 'off' => false,
        'null', ''            => $valeur === '' ? '' : null,
        default               => ctype_digit($valeur) ? (int) $valeur : $valeur,
    };
}

/**
 * Analyse un fichier `.env` : une paire `CLE=valeur` par ligne.
 *
 * Les lignes vides et celles commençant par `#` sont ignorées. La valeur peut
 * être entourée de guillemets — utile pour un mot de passe qui se termine par
 * une espace, ou qui contient un `#`.
 */
function lireEnv(string $chemin): array
{
    if (!is_readable($chemin)) return [];

    $valeurs = [];

    foreach (file($chemin, FILE_IGNORE_NEW_LINES) ?: [] as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || str_starts_with($ligne, '#')) continue;

        $coupure = strpos($ligne, '=');
        if ($coupure === false) continue;

        $cle = trim(substr($ligne, 0, $coupure));
        $valeur = trim(substr($ligne, $coupure + 1));

        // Guillemets encadrants : on les retire, le reste est pris tel quel.
        if (strlen($valeur) >= 2
            && (($valeur[0] === '"' && str_ends_with($valeur, '"'))
             || ($valeur[0] === "'" && str_ends_with($valeur, "'")))) {
            $valeur = substr($valeur, 1, -1);
        }

        if ($cle !== '') $valeurs[$cle] = $valeur;
    }

    return $valeurs;
}
