<?php
/** Racine : renvoie chacun vers l'écran d'accueil de son rôle. */

$session = sessionCourante();
rediriger($session ? accueilPour($session['role']) : '/login');
