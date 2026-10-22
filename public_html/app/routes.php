<?php
/**
 * Table de routage : motif d'URL → fichier de `app/pages/`.
 *
 * Les segments `{nom}` sont capturés dans `$params`. L'ordre compte : à
 * profondeur égale, une route fixe doit précéder une route à paramètre
 * (`/ventes/echeances` avant `/ventes/{type}`).
 */

return [
    '/'              => 'accueil.php',
    '/login'         => 'auth/login.php',
    '/deconnexion'   => 'auth/deconnexion.php',
    '/demande'       => 'demande.php',
    '/abonnement'    => 'abonnement.php',
    '/installation'  => 'installation.php',   // renvoie vers la console

    // ------------------------------------------- console de la plateforme
    // Ces pages travaillent sur le registre des sociétés, jamais sur les
    // données d'un client (voir `contexteRequete`).
    '/console'                        => 'console/tableau-de-bord.php',
    '/console/login'                  => 'console/login.php',
    '/console/deconnexion'            => 'console/deconnexion.php',
    '/console/installation'           => 'console/installation.php',
    '/console/demandes'               => 'console/societes.php',
    '/console/societes'               => 'console/societes.php',
    '/console/societes/nouvelle'      => 'console/societe-formulaire.php',
    '/console/societes/{id}'          => 'console/societe.php',
    '/console/societes/{id}/modifier' => 'console/societe-formulaire.php',
    '/console/sauvegardes'            => 'console/sauvegardes.php',
    '/console/sauvegardes/{id}'       => 'console/sauvegardes.php',
    '/console/journal'                => 'console/journal.php',
    '/console/reglages'               => 'console/reglages.php',

    '/tableau-de-bord' => 'tableau-de-bord.php',
    '/caisse'          => 'caisse.php',
    '/rapports'        => 'rapports.php',
    '/catalogue'       => 'catalogue.php',
    '/pos'             => 'pos.php',

    // ------------------------------------------------------------ utilisateurs
    '/utilisateurs'         => 'utilisateurs/liste.php',
    '/utilisateurs/nouveau' => 'utilisateurs/formulaire.php',
    '/utilisateurs/{id}'    => 'utilisateurs/formulaire.php',

    // ------------------------------------------------------------------- tiers
    '/tiers'                  => 'tiers/liste.php',
    '/tiers/clients'          => 'tiers/liste.php',
    '/tiers/fournisseurs'     => 'tiers/liste.php',
    '/tiers/nouveau'          => 'tiers/formulaire.php',
    '/tiers/groupes'          => 'tiers/groupes.php',
    '/tiers/groupes/{id}'     => 'tiers/groupe.php',
    '/tiers/{id}'             => 'tiers/fiche.php',
    '/tiers/{id}/modifier'    => 'tiers/formulaire.php',

    // ------------------------------------------------------------------- stock
    '/stock'                          => 'stock/index.php',
    '/stock/articles'                 => 'stock/articles.php',
    '/stock/articles/nouveau'         => 'stock/article-formulaire.php',
    '/stock/articles/importer'        => 'stock/importer.php',
    '/stock/articles/{id}'            => 'stock/article.php',
    '/stock/articles/{id}/modifier'   => 'stock/article-formulaire.php',
    '/stock/categories'               => 'stock/categories.php',
    '/stock/categories/{id}'          => 'stock/categorie.php',
    '/stock/marques'                  => 'stock/marques.php',
    '/stock/marques/{id}'             => 'stock/marque.php',
    '/stock/groupes-prix'             => 'stock/groupes-prix.php',
    '/stock/groupes-prix/{id}'        => 'stock/groupe-prix.php',
    '/stock/mouvements'               => 'stock/mouvements.php',
    '/stock/codes-barres'             => 'stock/codes-barres.php',

    // ------------------------------------------------------------------ ventes
    '/ventes'                    => 'documents/accueil-ventes.php',
    '/ventes/echeances'          => 'ventes/echeances.php',
    '/ventes/livraisons'         => 'ventes/livraisons.php',
    '/ventes/livraisons/{id}'    => 'ventes/livraison.php',
    '/ventes/{chemin}'           => 'documents/liste.php',
    '/ventes/{chemin}/nouveau'   => 'documents/formulaire.php',
    '/ventes/{chemin}/{id}'      => 'documents/fiche.php',
    '/ventes/{chemin}/{id}/modifier' => 'documents/formulaire.php',

    // ------------------------------------------------------------------ achats
    '/achats'                    => 'documents/accueil-achats.php',
    '/achats/{chemin}'           => 'documents/liste.php',
    '/achats/{chemin}/nouveau'   => 'documents/formulaire.php',
    '/achats/{chemin}/{id}'      => 'documents/fiche.php',
    '/achats/{chemin}/{id}/modifier' => 'documents/formulaire.php',

    // -------------------------------------------------------------- imprimerie
    '/imprimerie'                          => 'imprimerie/atelier.php',
    '/imprimerie/travaux'                  => 'imprimerie/travaux.php',
    '/imprimerie/travaux/nouveau'          => 'imprimerie/travail-formulaire.php',
    '/imprimerie/travaux/{id}'             => 'imprimerie/travail.php',
    '/imprimerie/travaux/{id}/modifier'    => 'imprimerie/travail-formulaire.php',
    '/imprimerie/fiche/{id}'               => 'imprimerie/fiche.php',
    '/imprimerie/consommations'            => 'imprimerie/consommations.php',
    '/imprimerie/comptoir'                 => 'imprimerie/comptoir.php',
    '/imprimerie/articles'                 => 'imprimerie/articles.php',
    '/imprimerie/machines'                 => 'imprimerie/machines.php',
    '/imprimerie/rapport'                  => 'imprimerie/rapport.php',

    // --------------------------------------------------------- chèques / effets
    '/effets'          => 'effets/index.php',
    '/effets/cheques'  => 'effets/liste.php',
    '/effets/effets'   => 'effets/liste.php',
    '/effets/rapport'  => 'effets/rapport.php',
    '/effets/nouveau'  => 'effets/formulaire.php',
    '/effets/{id}'     => 'effets/fiche.php',

    // -------------------------------------------------------------- inventaire
    '/inventaire'       => 'inventaire/liste.php',
    '/inventaire/{id}'  => 'inventaire/fiche.php',

    // ----------------------------------------------------------- documentation
    '/documentation'                 => 'documentation/liste.php',
    '/documentation/nouveau'         => 'documentation/formulaire.php',
    '/documentation/{id}'            => 'documentation/fiche.php',
    '/documentation/{id}/modifier'   => 'documentation/formulaire.php',

    // ---------------------------------------------------------------- dépenses
    '/depenses'              => 'depenses/liste.php',
    '/depenses/nouvelle'     => 'depenses/formulaire.php',
    '/depenses/consommation' => 'depenses/consommation.php',
    '/depenses/categories'   => 'depenses/categories.php',
    '/depenses/{id}'         => 'depenses/fiche.php',

    // -------------------------------------------------------------- paramètres
    '/parametres'            => 'parametres/index.php',
    '/parametres/sauvegarde' => 'parametres/sauvegarde.php',

    // ------------------------------------------------------- impression & public
    '/impression/{id}' => 'impression.php',

    // Synthèses imprimables : caisse, activité, tableau de bord. Trois segments,
    // aucun risque de collision avec l'impression d'un document.
    '/impression/rapport/{rapport}' => 'impression-rapport.php',

    // Pages publiques : l'adresse du catalogue, choisie par le commerçant,
    // désigne à elle seule la société — pas de session, et surtout pas de code
    // société dans une URL qu'on imprime.
    '/c/{adresse}'                => 'public/catalogue.php',
    '/p/{adresse}/images/{id}'    => 'api/image.php',

    // -------------------------------------------------------------------- API
    '/api/images/{id}'   => 'api/image.php',
    '/api/export/{quoi}' => 'api/export.php',
    '/api/sauvegarde'    => 'api/sauvegarde.php',
    '/api/retours'       => 'api/retours.php',
];
