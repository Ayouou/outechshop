<?php
/** Export JSON de la base entière (administrateur uniquement). */

$session = sessionCourante();
if (!$session || $session['role'] !== 'ADMIN') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['erreur' => 'Réservé à l\'administrateur']));
}

$nom = 'outech-sauvegarde-' . date('Y-m-d-His') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nom . '"');

echo json_encode(exporterTout(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
