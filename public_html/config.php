<?php
/**
 * Configuration — la structure est ici, les valeurs sont dans `.env`.
 *
 * Ce fichier ne contient plus aucun identifiant : il se contente d'assembler
 * ce que `.env` (et `.env.local` sur le poste de développement) lui donnent.
 * Il peut donc être téléversé tel quel, d'une machine à l'autre, sans jamais
 * écraser la connexion du serveur.
 *
 * Les valeurs par défaut ci-dessous servent de repli quand aucun `.env`
 * n'existe encore — le temps de la première mise en service.
 */

require_once __DIR__ . '/app/lib/env.php';

return [
    // ------------------------------------------------------------ base MySQL
    'bd' => [
        // `mysql` sur le serveur, `sqlite` pour la version de bureau (.exe).
        'pilote'      => env('BD_PILOTE', 'mysql'),
        // SQLite uniquement : dossier des fichiers `.sqlite` (vide = ./donnees).
        'dossier'     => env('BD_DOSSIER', ''),
        'hote'        => env('BD_HOTE', 'localhost'),
        'port'        => env('BD_PORT', 3306),
        // Base de repli, utilisée tant qu'aucune société n'est sélectionnée.
        'base'        => env('BD_BASE', 'outech'),
        'utilisateur' => env('BD_UTILISATEUR', 'root'),
        'motdepasse'  => env('BD_MOTDEPASSE', ''),
        // Laisser vide sauf hébergeur imposant un socket particulier.
        'socket'      => env('BD_SOCKET', ''),
    ],

    // ------------------------------------------------------- multi-sociétés
    'saas' => [
        'console'        => env('SAAS_CONSOLE', 'outech_console'),
        'prefixe'        => env('SAAS_PREFIXE', 'outech_'),
        'creation_bases' => env('SAAS_CREATION_BASES', true),
    ],

    'fuseau'   => env('FUSEAU', 'Africa/Casablanca'),
    'debogage' => env('DEBOGAGE', false),
];
