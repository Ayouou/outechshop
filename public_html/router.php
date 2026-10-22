<?php
/**
 * Routeur du serveur intégré PHP — pratique pour essayer l'application sans
 * Apache : `php -S localhost:8000 router.php` depuis ce dossier.
 *
 * En production (XAMPP, hébergement mutualisé), c'est `.htaccess` qui fait ce
 * travail et ce fichier n'est jamais appelé.
 */

if (PHP_SAPI === 'cli-server') {
    $fichier = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Les fichiers réellement présents (assets, favicon) sont servis tels quels.
    if (is_file($fichier)) return false;
}

require __DIR__ . '/index.php';
