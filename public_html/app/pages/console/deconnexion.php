<?php
/** Sortie de la console (POST uniquement, jeton CSRF vérifié en amont). */

if (!estPost()) rediriger('/console');

fermerSessionConsole();
rediriger('/console/login', 'Vous êtes déconnecté de la console.');
