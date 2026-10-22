<?php
/**
 * Sert une photo stockée en base.
 *
 * Cache permanent : l'identifiant d'une image ne change jamais, une photo
 * remplacée en crée une nouvelle.
 */

$image = un('SELECT mime, taille, donnees FROM image WHERE id = ?', [$params['id']]);

if (!$image) {
    http_response_code(404);
    exit('Image introuvable');
}

$donnees = is_resource($image['donnees']) ? stream_get_contents($image['donnees']) : $image['donnees'];

header('Content-Type: ' . $image['mime']);
header('Content-Length: ' . strlen((string) $donnees));
header('Cache-Control: public, max-age=31536000, immutable');

echo $donnees;
