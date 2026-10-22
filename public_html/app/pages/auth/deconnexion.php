<?php
/** Fermeture de session (POST uniquement, jeton CSRF vérifié en amont). */

if (!estPost()) rediriger('/');

// Espace ouvert depuis la console : on rend la main à la console, qui reste
// connectée, plutôt que de renvoyer le gestionnaire sur le login client.
$retourConsole = enEspaceConsole() && sessionConsole();

fermerSession();

if ($retourConsole) rediriger('/console/societes', "Espace client refermé.");
rediriger('/login', 'Vous êtes déconnecté.');
