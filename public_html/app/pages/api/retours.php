<?php
/**
 * Historique retournable d'un tiers : ce qu'il a livré (ou reçu) sur la
 * période, moins ce qui a déjà été retourné. Consommé par le formulaire de
 * retour d'achat et d'avoir.
 */

header('Content-Type: application/json; charset=utf-8');

$session = sessionCourante();
if (!$session) {
    http_response_code(401);
    exit(json_encode(['erreur' => 'Non autorisé']));
}

$type = (string) param('type', '');
if (!isset(DOCUMENTS[$type])) {
    http_response_code(400);
    exit(json_encode(['erreur' => 'Type de document inconnu.']));
}

$tiersId = (string) param('tiers_id', '');
if ($tiersId === '') exit(json_encode(['lignes' => []]));

$demandee = (int) param('jours', 0);
$jours = in_array($demandee, [30, 90, 180, 365], true) ? $demandee : periodeParDefaut();

echo json_encode([
    'jours'  => $jours,
    'lignes' => lignesRetournables($type, $tiersId, $session['lieu_id'], $jours),
], JSON_UNESCAPED_UNICODE);
