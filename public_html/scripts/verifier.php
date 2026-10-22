<?php
/**
 * Test de fumée : parcourt toutes les routes avec une session valide et
 * signale celles qui ne répondent pas 200.
 *
 * Deux sessions sont ouvertes : celle d'une société cliente (routes de
 * l'application) et celle de la console (routes de la plateforme).
 *
 * Usage :  php scripts/verifier.php [url] [code] [identifiant] [motdepasse] [console] [motdepasse-console]
 *   ex.    php scripts/verifier.php http://localhost:8181 atlas admin admin123 plateforme secret123
 */

$base = rtrim($argv[1] ?? 'http://localhost:8181', '/');
$code = $argv[2] ?? 'demo';
$identifiant = $argv[3] ?? 'admin';
$motDePasse = $argv[4] ?? 'admin123';
$consoleUser = $argv[5] ?? null;
$consolePass = $argv[6] ?? null;

function appeler(string $url, array $session, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $session['fichier'],
        CURLOPT_COOKIEFILE     => $session['fichier'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $corps = curl_exec($ch);
    $codeHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $codeHttp, 'corps' => (string) $corps];
}

/** Jeton CSRF de la page demandée. */
function jeton(string $corps): string
{
    preg_match('/name="_csrf" value="([^"]+)"/', $corps, $m);
    return $m[1] ?? '';
}

/* ------------------------------------------------- session société cliente */

$session = ['fichier' => tempnam(sys_get_temp_dir(), 'outech')];

$connexion = appeler("$base/login", $session, [
    '_csrf'        => jeton(appeler("$base/login", $session)['corps']),
    'code'         => $code,
    'username'     => $identifiant,
    'mot_de_passe' => $motDePasse,
]);

if (str_contains($connexion['corps'], 'Identifiants incorrects')
    || str_contains($connexion['corps'], 'Code société inconnu')) {
    exit("Connexion refusée pour « $identifiant » sur la société « $code ».\n");
}

/* --------------------------------------------------------- session console */

$console = null;
if ($consoleUser !== null) {
    $console = ['fichier' => tempnam(sys_get_temp_dir(), 'outechc')];
    $reponse = appeler("$base/console/login", $console, [
        '_csrf'        => jeton(appeler("$base/console/login", $console)['corps']),
        'username'     => $consoleUser,
        'mot_de_passe' => (string) $consolePass,
    ]);
    if (str_contains($reponse['corps'], 'Identifiants incorrects')) {
        exit("Connexion console refusée pour « $consoleUser ».\n");
    }
}

/* ------------------------------------ identifiants réels pour les routes {id} */

$routes = require __DIR__ . '/../app/routes.php';
$config = require __DIR__ . '/../config.php';

// Même couche d'accès que l'application : MySQL ou SQLite selon la config.
if (!defined('RACINE')) define('RACINE', dirname(__DIR__));
require_once RACINE . '/app/lib/db.php';
$ouvrir = fn (string $nomBase) => ouvrirPdo($nomBase);

$registre = $ouvrir($config['saas']['console'] ?? 'outech_console');
$st = $registre->prepare('SELECT * FROM saas_societe WHERE code = ?');
$st->execute([strtolower($code)]);
$societe = $st->fetch(PDO::FETCH_ASSOC);

if (!$societe || empty($societe['base'])) exit("Société « $code » sans espace installé.\n");

$pdo = $ouvrir($societe['base']);

$st = $registre->prepare('SELECT adresse FROM saas_catalogue WHERE societe_id = ? LIMIT 1');
$st->execute([$societe['id']]);
$adressePublique = $st->fetchColumn() ?: null;

$premier = function (string $table) use ($pdo) {
    $id = $pdo->query("SELECT id FROM `$table` LIMIT 1")->fetchColumn();
    return $id === false ? null : $id;
};

$exemples = [
    '/tiers/{id}'                    => $premier('tiers'),
    '/tiers/{id}/modifier'           => $premier('tiers'),
    '/tiers/groupes/{id}'            => $premier('groupe_client'),
    '/stock/articles/{id}'           => $premier('article'),
    '/stock/articles/{id}/modifier'  => $premier('article'),
    '/stock/categories/{id}'         => $premier('categorie'),
    '/stock/marques/{id}'            => $premier('marque'),
    '/stock/groupes-prix/{id}'       => $premier('groupe_prix'),
    '/effets/{id}'                   => $premier('effet'),
    '/inventaire/{id}'               => $premier('inventaire'),
    '/documentation/{id}'            => $premier('documentation'),
    '/documentation/{id}/modifier'   => $premier('documentation'),
    '/depenses/{id}'                 => $premier('depense'),
    '/utilisateurs/{id}'             => $premier('utilisateur'),
    '/impression/{id}'               => $premier('document'),
    '/imprimerie/travaux/{id}'          => $premier('travail'),
    '/imprimerie/travaux/{id}/modifier' => $premier('travail'),
    '/imprimerie/fiche/{id}'            => $premier('travail'),
    '/api/images/{id}'               => $premier('image'),
    '/p/{adresse}/images/{id}'       => $premier('image'),
    '/console/societes/{id}'         => $societe['id'],
    '/console/societes/{id}/modifier'=> $societe['id'],
    // La sauvegarde renvoie un fichier : testée à part, elle n'est pas une page.
    '/console/sauvegardes/{id}'      => null,
];

$documentDeType = function (string $type) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM document WHERE type = ? LIMIT 1');
    $st->execute([$type]);
    $id = $st->fetchColumn();
    return $id === false ? null : $id;
};

$exemples['/ventes/livraisons/{id}'] = $documentDeType('BON_LIVRAISON');

foreach (['/ventes' => 'FACTURE', '/achats' => 'ACHAT'] as $prefixe => $type) {
    $exemples["$prefixe/{chemin}/{id}"] = $documentDeType($type);
    $exemples["$prefixe/{chemin}/{id}/modifier"] = $documentDeType($type);
}

/* ------------------------------------------------------------- le parcours */

$echecs = 0;
$testees = 0;

foreach ($routes as $motif => $fichier) {
    if (in_array($motif, ['/deconnexion', '/installation', '/console/deconnexion',
                          '/console/installation', '/console/sauvegardes/{id}'], true)) continue;

    $estConsole = str_starts_with($motif, '/console');
    if ($estConsole && $console === null) { echo "· ignorée (pas de session console) : $motif\n"; continue; }

    $chemin = $motif;

    if (str_contains($motif, '{chemin}')) {
        $chemin = str_replace('{chemin}', str_starts_with($motif, '/ventes') ? 'factures' : 'liste', $motif);
    }

    // Les pages publiques s'adressent par le nom du catalogue, pris dans le
    // registre : c'est exactement ce que le visiteur a dans son navigateur.
    if (str_contains($chemin, '{adresse}')) {
        if ($adressePublique === null) { echo "· ignorée (aucun catalogue publié) : $motif\n"; continue; }
        $chemin = str_replace('{adresse}', $adressePublique, $chemin);
    }

    if (str_contains($chemin, '{id}')) {
        $exemple = $exemples[$motif] ?? null;
        if (!$exemple) { echo "· ignorée (aucune donnée) : $motif\n"; continue; }
        $chemin = str_replace('{id}', $exemple, $chemin);
    }

    if (str_contains($chemin, '{quoi}')) $chemin = str_replace('{quoi}', 'articles', $chemin);

    // Synthèses imprimables : la caisse est celle que tout rôle de gestion voit.
    if (str_contains($chemin, '{rapport}')) $chemin = str_replace('{rapport}', 'caisse', $chemin);

    // L'historique retournable exige un type de document et un tiers.
    if ($chemin === '/api/retours') {
        $tiersId = $pdo->query('SELECT id FROM tiers LIMIT 1')->fetchColumn();
        $chemin .= '?type=RETOUR_ACHAT&tiers_id=' . rawurlencode((string) $tiersId);
    }

    $reponse = appeler($base . $chemin, $estConsole ? $console : $session);
    $testees++;

    if ($reponse['code'] !== 200) {
        $echecs++;
        echo "✗ {$reponse['code']}  $chemin\n";
        continue;
    }

    // Une erreur PHP affichée renvoie tout de même 200 : on la cherche dans le corps.
    if (preg_match('/(Fatal error|Warning|Notice|Uncaught)/', $reponse['corps'], $erreur)) {
        $echecs++;
        echo "✗ {$erreur[1]} dans $chemin\n";
        continue;
    }

    echo "✓ $chemin\n";
}

@unlink($session['fichier']);
if ($console) @unlink($console['fichier']);

echo "\n$testees route(s) testée(s), $echecs échec(s).\n";
exit($echecs > 0 ? 1 : 0);
