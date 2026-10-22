<?php
/**
 * Diagnostic d'installation — à déposer à la racine (à côté de `index.php`),
 * ouvrir dans le navigateur, puis **supprimer du serveur**.
 *
 * Il vérifie, dans l'ordre : la version de PHP, les extensions nécessaires, la
 * lecture de `config.php`, la connexion MySQL, la présence des tables et celle
 * d'un compte utilisateur. Le mot de passe n'est jamais affiché.
 */

header('Content-Type: text/html; charset=utf-8');

$racine = __DIR__;
if (!is_file($racine . '/index.php') && is_file(dirname($racine) . '/index.php')) {
    // Fichier resté dans `install/` : on remonte d'un cran.
    $racine = dirname($racine);
}

$lignes = [];

function verdict(string $etape, bool $ok, string $detail, ?string $remede = null): void
{
    global $lignes;
    $lignes[] = ['etape' => $etape, 'ok' => $ok, 'detail' => $detail, 'remede' => $remede];
}

/* --------------------------------------------------------------------- PHP */

$versionOk = PHP_VERSION_ID >= 80100;
verdict('Version de PHP', $versionOk, PHP_VERSION,
    $versionOk ? null : "OUTECH demande PHP 8.1 ou plus récent. Changez la version dans le panneau de l'hébergeur.");

foreach (['pdo_mysql' => 'obligatoire (connexion à MySQL)',
          'mbstring'  => 'obligatoire (accents)',
          'zip'       => "facultatif (import de fichiers .xlsx)"] as $extension => $role) {
    $present = extension_loaded($extension);
    verdict("Extension $extension", $present || $extension === 'zip',
        $present ? 'présente' : 'absente — ' . $role,
        $present ? null : "Activez l'extension $extension dans le panneau de l'hébergeur.");
}

/* ------------------------------------------------------------------ config */

$config = null;
$cheminConfig = $racine . '/config.php';

if (!is_file($cheminConfig)) {
    verdict('Fichier config.php', false, "introuvable dans $racine",
        'Le fichier `config.php` manque à la racine, à côté de `index.php`.');
} else {
    $config = @include $cheminConfig;

    if (!is_array($config)) {
        verdict('Fichier config.php', false, 'lu, mais il ne renvoie pas de tableau',
            'Le fichier doit commencer par `return [` et se terminer par `];`.');
    } elseif (!isset($config['bd'])) {
        verdict('Fichier config.php', false, "la clé « bd » est absente",
            "Vérifiez que `config.php` se termine bien par `];`.");
    } else {
        verdict('Fichier config.php', true, 'lu et bien formé');
    }
}

/* ------------------------------------------------------------------- MySQL */

$pdo = null;

if (is_array($config) && isset($config['bd'])) {
    $bd = $config['bd'] + ['hote' => 'localhost', 'port' => 3306, 'base' => '', 'utilisateur' => '', 'motdepasse' => '', 'socket' => ''];

    verdict('Paramètres lus', true,
        "hôte « {$bd['hote']} », base « {$bd['base']} », utilisateur « {$bd['utilisateur']} » (mot de passe : "
        . ($bd['motdepasse'] === '' ? 'vide' : strlen((string) $bd['motdepasse']) . ' caractères') . ')');

    $dsn = $bd['socket']
        ? "mysql:unix_socket={$bd['socket']};dbname={$bd['base']};charset=utf8mb4"
        : "mysql:host={$bd['hote']};port={$bd['port']};dbname={$bd['base']};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $bd['utilisateur'], $bd['motdepasse'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        verdict('Connexion MySQL', true, 'établie — serveur ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    } catch (PDOException $e) {
        $message = $e->getMessage();

        // Les trois causes qui reviennent toujours, traduites en gestes concrets.
        $remede = "Vérifiez les valeurs dans config.php.";
        if (str_contains($message, 'Access denied')) {
            $remede = "Identifiant ou mot de passe refusé. Sur un hébergement mutualisé, "
                . "l'utilisateur porte le même préfixe que la base (ex. u123456_outech / u123456_moncompte). "
                . "Au moindre doute, redéfinissez le mot de passe depuis le panneau et recopiez-le.";
        } elseif (str_contains($message, 'Unknown database')) {
            $remede = "La base « {$bd['base']} » n'existe pas sous ce nom. Recopiez-le depuis le panneau, "
                . "préfixe compris.";
        } elseif (str_contains($message, 'refused') || str_contains($message, '2002')) {
            $remede = "Serveur MySQL injoignable depuis « {$bd['hote']} ». En local, démarrez MySQL dans XAMPP. "
                . "Chez un hébergeur, l'hôte est souvent « localhost » — mais certains imposent une adresse "
                . "propre, indiquée dans le panneau à côté de la base.";
        }

        verdict('Connexion MySQL', false, $message, $remede);
    }
}

/* ------------------------------------------------------------------ tables */

if ($pdo) {
    $attendues = ['societe', 'lieu_affaire', 'utilisateur', 'tiers', 'article', 'stock',
        'document', 'ligne_document', 'paiement', 'effet', 'mouvement_stock', 'depense',
        'inventaire', 'catalogue', 'livraison', 'documentation', 'image', 'sequence'];

    $presentes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $manquantes = array_diff($attendues, $presentes);

    verdict('Tables de OUTECH', !$manquantes,
        $manquantes ? 'manquantes : ' . implode(', ', $manquantes) : count($presentes) . ' tables en place',
        $manquantes ? "Importez `install/outech.sql` dans cette base depuis phpMyAdmin." : null);

    if (!$manquantes) {
        $comptes = (int) $pdo->query('SELECT COUNT(*) FROM utilisateur')->fetchColumn();
        verdict('Comptes utilisateurs', $comptes > 0,
            $comptes > 0 ? "$comptes compte(s)" : 'aucun compte',
            $comptes > 0 ? null : "Ouvrez /installation, ou importez `install/donnees-minimales.sql`.");
    }
}

/* ----------------------------------------------------------------- écriture */

$dossierSessions = session_save_path() ?: sys_get_temp_dir();
$ecriture = is_writable($dossierSessions);
verdict('Dossier des sessions', $ecriture, $dossierSessions . ($ecriture ? ' — accessible en écriture' : ' — non accessible'),
    $ecriture ? null : "Sans écriture ici, la connexion ne tiendra pas : demandez à l'hébergeur un dossier de sessions inscriptible.");

$total = count($lignes);
$echecs = count(array_filter($lignes, fn($l) => !$l['ok']));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostic OUTECH</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f1f4f9; color: #1f2937; margin: 0; padding: 2rem 1rem; }
  main { max-width: 46rem; margin: 0 auto; }
  h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
  p.sous { color: #64748b; margin: 0 0 1.5rem; }
  .ligne { background: #fff; border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: .75rem;
           box-shadow: 0 1px 2px rgb(0 0 0 / .05); border-left: 4px solid #10b981; }
  .ligne.ko { border-left-color: #e11d48; }
  .etape { font-weight: 600; }
  .detail { color: #475569; font-size: .9rem; margin-top: .25rem; word-break: break-word; }
  .remede { background: #fff7ed; color: #9a3412; font-size: .9rem; margin-top: .5rem;
            padding: .5rem .75rem; border-radius: .5rem; }
  .bilan { background: #fff; border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-weight: 600; }
  .rappel { margin-top: 1.5rem; background: #fef2f2; color: #991b1b; padding: 1rem 1.25rem; border-radius: .75rem; }
</style>
</head>
<body>
<main>
  <h1>Diagnostic OUTECH</h1>
  <p class="sous">Vérification de l'installation sur ce serveur.</p>

  <div class="bilan"><?= $echecs === 0
      ? "Tout est en place ($total contrôles) — ouvrez l'application."
      : "$echecs problème(s) sur $total contrôles." ?></div>

  <?php foreach ($lignes as $ligne): ?>
    <div class="ligne <?= $ligne['ok'] ? '' : 'ko' ?>">
      <div class="etape"><?= $ligne['ok'] ? '✓' : '✗' ?> <?= htmlspecialchars($ligne['etape']) ?></div>
      <div class="detail"><?= htmlspecialchars($ligne['detail']) ?></div>
      <?php if (!$ligne['ok'] && $ligne['remede']): ?>
        <div class="remede"><?= htmlspecialchars($ligne['remede']) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <p class="rappel"><strong>Supprimez ce fichier du serveur</strong> une fois le diagnostic terminé :
     il renseigne un visiteur sur votre installation.</p>
</main>
</body>
</html>
