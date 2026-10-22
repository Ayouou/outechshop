<?php
/**
 * OUTECH — point d'entrée unique.
 *
 * Toutes les URL passent ici (voir `.htaccess`) : le routeur traduit le chemin
 * demandé en fichier de `app/pages/`, `contexteRequete()` choisit la base de la
 * société concernée, puis la page se charge de sa propre logique et rend son
 * HTML via `page()`.
 */

define('RACINE', __DIR__);

require RACINE . '/app/lib/db.php';

error_reporting(E_ALL);
ini_set('display_errors', config('debogage') ? '1' : '0');
date_default_timezone_set(config('fuseau') ?: 'Africa/Casablanca');
mb_internal_encoding('UTF-8');

// Une base injoignable ou un incident non rattrapé donnent une page lisible
// plutôt qu'une trace : le détail n'apparaît qu'en débogage.
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Erreur</title>'
        . '<body style="font-family:system-ui;padding:3rem;color:#334155;max-width:42rem;margin:auto">'
        . '<h1 style="font-size:1.25rem">Le service est momentanément indisponible</h1>';
    echo config('debogage')
        ? '<pre style="white-space:pre-wrap;background:#f1f5f9;padding:1rem;border-radius:.5rem">'
            . htmlspecialchars($e->getMessage()) . '</pre>'
        : '<p>Réessayez dans un instant. Si cela persiste, prévenez votre prestataire.</p>';
});

require RACINE . '/app/lib/format.php';
require RACINE . '/app/lib/routeur.php';
require RACINE . '/app/lib/navigation.php';
require RACINE . '/app/lib/documents.php';
require RACINE . '/app/lib/auth.php';
require RACINE . '/app/lib/saas.php';
require RACINE . '/app/lib/console.php';
require RACINE . '/app/lib/calculs.php';
require RACINE . '/app/lib/liste.php';
require RACINE . '/app/lib/synthese.php';
require RACINE . '/app/lib/numerotation.php';
require RACINE . '/app/lib/stock.php';
require RACINE . '/app/lib/consommables.php';
require RACINE . '/app/lib/imprimerie.php';
require RACINE . '/app/lib/ventes.php';
require RACINE . '/app/lib/facturation.php';
require RACINE . '/app/lib/retours.php';
require RACINE . '/app/lib/images.php';
require RACINE . '/app/lib/tableur.php';
require RACINE . '/app/lib/journal.php';
require RACINE . '/app/lib/ubl.php';
require RACINE . '/app/lib/boutique.php';
require RACINE . '/app/lib/operations.php';
require RACINE . '/app/lib/sauvegarde.php';
require RACINE . '/app/vues/icones.php';
require RACINE . '/app/vues/ui.php';
require RACINE . '/app/vues/graphique.php';
require RACINE . '/app/vues/boutique.php';
require RACINE . '/app/vues/layout.php';
require RACINE . '/app/vues/console-vue.php';

demarrerSession();

$chemin = cheminDemande();

// Domaine propre : quand la requete arrive par l'hote d'un commercant, la
// racine sert son catalogue. L'adresse `/c/<slug>` continue de fonctionner —
// c'est le meme catalogue, pas une copie.
$adresseDomaine = catalogueParDomaine(hoteDemande());
if ($adresseDomaine !== null && ($chemin === '/' || $chemin === '')) {
    $chemin = '/c/' . $adresseDomaine;
}

$routes = require RACINE . '/app/routes.php';

$fichier = resoudre($routes, $chemin, $params);
if ($fichier === null) introuvable();

// Aucun POST n'est accepté sans jeton : un formulaire hébergé ailleurs ne peut
// pas agir sur la session de l'utilisateur.
verifierCsrf();

// Choisit la base sur laquelle travaille la requête — registre de la
// plateforme, ou base de la société concernée — et vérifie l'abonnement.
// Passé ce point, aucune requête ne peut atteindre les données d'une autre
// société : la connexion elle-même ne le permet pas.
contexteRequete($fichier, $params);

// Un poste d'atelier (designer, machiniste) ne sort pas de l'imprimerie, quelle
// que soit l'adresse demandée.
verifierPortee();

require RACINE . '/app/pages/' . $fichier;
