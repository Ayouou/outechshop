<?php
/**
 * Routage : traduit l'URL demandée en fichier de page, et fournit les helpers
 * d'URL / de redirection utilisés partout ailleurs.
 *
 * L'application n'exige pas d'être installée à la racine du domaine : le
 * chemin de base est déduit de `SCRIPT_NAME`, donc `http://localhost/outech-php/`
 * fonctionne aussi bien qu'un domaine dédié.
 */

/** Préfixe d'installation, sans slash final (« » ou « /outech-php »). */
function base(): string
{
    static $base = null;
    if ($base !== null) return $base;

    $dossier = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $base = ($dossier === '/' || $dossier === '.') ? '' : rtrim($dossier, '/');
}

/** URL absolue (chemin) vers une route de l'application. */
function u(string $chemin = '/'): string
{
    if (preg_match('#^(https?:)?//#', $chemin)) return $chemin;
    return base() . '/' . ltrim($chemin, '/');
}

/**
 * URL absolue (schéma + hôte) d'une route : c'est celle qu'on communique au
 * client ou qu'on encode dans un QR code.
 */
function urlAbsolue(string $chemin = '/'): string
{
    $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $schema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . u($chemin);
}

/** Chemin demandé, relatif à l'installation, sans query string. */
function cheminDemande(): string
{
    static $chemin = null;
    if ($chemin !== null) return $chemin;

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rawurldecode($uri);

    $base = base();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    // Accès direct à index.php (hébergeur sans réécriture d'URL).
    $uri = preg_replace('#^/index\.php#', '', $uri) ?? $uri;
    if ($uri === '' || $uri[0] !== '/') $uri = '/' . $uri;

    return $chemin = rtrim($uri, '/') ?: '/';
}

function estPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Redirection puis arrêt du script. */
function rediriger(string $chemin, ?string $message = null, string $ton = 'succes'): void
{
    if ($message !== null) flash($message, $ton);
    header('Location: ' . u($chemin));
    exit;
}

/** Message éphémère affiché après une redirection. */
function flash(?string $message = null, string $ton = 'succes'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'ton' => $ton];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** Paramètre GET. */
function param(string $cle, $defaut = null)
{
    $valeur = $_GET[$cle] ?? $defaut;
    return is_string($valeur) ? trim($valeur) : $valeur;
}

/** Champ de formulaire (POST). */
function champ(string $cle, $defaut = null)
{
    $valeur = $_POST[$cle] ?? $defaut;
    return is_string($valeur) ? trim($valeur) : $valeur;
}

function champNombre(string $cle, float $defaut = 0): float
{
    return nombreSaisi($_POST[$cle] ?? null, $defaut);
}

function champBool(string $cle): bool
{
    $valeur = $_POST[$cle] ?? null;
    return $valeur === '1' || $valeur === 'on' || $valeur === 'true';
}

/** Chaîne vide → null, pour les colonnes nullables. */
function ouNull($valeur): ?string
{
    if ($valeur === null) return null;
    $texte = trim((string) $valeur);
    return $texte === '' ? null : $texte;
}

/**
 * Résout le chemin demandé en fichier de page.
 * Les segments `{nom}` deviennent des entrées du tableau `$params`.
 */
function resoudre(array $routes, string $chemin, ?array &$params): ?string
{
    $params = [];
    $segments = array_values(array_filter(explode('/', $chemin), fn($s) => $s !== ''));

    foreach ($routes as $motif => $fichier) {
        $attendus = array_values(array_filter(explode('/', $motif), fn($s) => $s !== ''));
        if (count($attendus) !== count($segments)) continue;

        $trouves = [];
        $ok = true;
        foreach ($attendus as $i => $attendu) {
            if (str_starts_with($attendu, '{') && str_ends_with($attendu, '}')) {
                $trouves[trim($attendu, '{}')] = $segments[$i];
                continue;
            }
            if ($attendu !== $segments[$i]) { $ok = false; break; }
        }
        if ($ok) {
            $params = $trouves;
            return $fichier;
        }
    }
    return null;
}

/** Page 404 minimale (les pages internes utilisent `introuvable()`). */
function introuvable(string $message = 'Page introuvable'): void
{
    http_response_code(404);
    if (function_exists('sessionCourante') && sessionCourante()) {
        page($message, function () use ($message) {
            echo '<div class="rounded-xl bg-white p-10 text-center shadow-sm ring-1 ring-slate-200/70">'
                . '<p class="text-slate-500">' . e($message) . '</p></div>';
        });
        exit;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Introuvable</title>'
        . '<body style="font-family:system-ui;padding:3rem;text-align:center;color:#334155">'
        . '<h1>404</h1><p>' . e($message) . '</p>'
        . '<p><a href="' . e(u('/')) . '">Retour à l\'accueil</a></p>';
    exit;
}
