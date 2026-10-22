<?php
/**
 * Structure du menu latéral. `icone` référence une clé de `app/vues/icones.php`.
 * Port de `src/lib/navigation.ts`.
 */

/**
 * Rôles autorisés à consulter les synthèses de gestion (tableau de bord,
 * rapports).
 *
 * Le caissier en est écarté : son écran, c'est la caisse. Le commercial aussi :
 * il vend et facture, mais la marge, le résultat et la valeur du stock ne le
 * regardent pas.
 */
const ROLES_SYNTHESE = ['ADMIN', 'MANAGER'];

/**
 * Les deux postes de l'atelier d'impression.
 *
 * Un designer ou un machiniste ne tient pas la boutique : il ne voit ni les
 * ventes, ni les achats, ni la caisse, ni le moindre montant — seulement les
 * dossiers à produire. C'est pour cela que toutes les entrées de gestion
 * portent `ROLES_GESTION`, qui les exclut.
 */
const ROLES_ATELIER = ['DESIGNER', 'MACHINISTE'];

/**
 * Qui entre dans l'atelier — tout le monde sauf le commercial : il prend la
 * commande et la facture, la production se passe de lui. `ROLES_IMPRIMERIE`
 * ouvre la section, `ROLES_IMPRIMERIE_GESTION` les écrans qui engagent la
 * matière ou l'argent (nouveau travail, comptoir, machines).
 */
const ROLES_IMPRIMERIE = ['ADMIN', 'MANAGER', 'CAISSIER', 'DESIGNER', 'MACHINISTE'];
const ROLES_IMPRIMERIE_GESTION = ['ADMIN', 'MANAGER', 'CAISSIER'];

/** Rôles qui tiennent le commerce : tout le reste du logiciel est pour eux. */
const ROLES_GESTION = ['ADMIN', 'MANAGER', 'COMMERCIAL', 'CAISSIER'];

/**
 * Rôles qui peuvent lire un montant.
 *
 * L'atelier travaille sur des dimensions et des supports, pas sur des prix :
 * le total d'un dossier, l'avance et le coût des chutes lui sont masqués. La
 * règle est appliquée écran par écran, pas seulement dans le menu.
 */
function voitMontants(?string $role): bool
{
    return !in_array((string) $role, ROLES_ATELIER, true);
}

function navigation(): array
{
    return [
        ['libelle' => 'Tableau de bord', 'href' => '/tableau-de-bord', 'icone' => 'jauge', 'roles' => ROLES_SYNTHESE],
        ['libelle' => 'La caisse', 'href' => '/caisse', 'icone' => 'caisse', 'roles' => ROLES_GESTION],
        ['libelle' => 'Les utilisateurs', 'href' => '/utilisateurs', 'icone' => 'utilisateurs', 'roles' => ['ADMIN']],
        ['libelle' => 'Clients / Fournisseurs', 'href' => '/tiers', 'icone' => 'carnet', 'roles' => ROLES_GESTION, 'enfants' => [
            ['libelle' => 'Clients', 'href' => '/tiers/clients'],
            ['libelle' => 'Groupes de clients', 'href' => '/tiers/groupes'],
            ['libelle' => 'Fournisseurs', 'href' => '/tiers/fournisseurs'],
        ]],
        ['libelle' => 'Gestion des stocks', 'href' => '/stock', 'icone' => 'boite', 'roles' => ROLES_GESTION, 'enfants' => [
            ['libelle' => 'Liste des articles', 'href' => '/stock/articles'],
            ['libelle' => 'Ajouter un article', 'href' => '/stock/articles/nouveau'],
            ['libelle' => 'Importer des articles', 'href' => '/stock/articles/importer'],
            ['libelle' => 'Catégories', 'href' => '/stock/categories'],
            ['libelle' => 'Marques', 'href' => '/stock/marques'],
            ['libelle' => 'Groupes de prix de vente', 'href' => '/stock/groupes-prix'],
            ['libelle' => 'Mouvements de stock', 'href' => '/stock/mouvements'],
            ['libelle' => 'Étiquettes code-barres', 'href' => '/stock/codes-barres'],
        ]],
        ['libelle' => 'Gestion des achats', 'href' => '/achats', 'icone' => 'panier', 'roles' => ROLES_GESTION, 'enfants' => [
            ['libelle' => 'Bons de commande', 'href' => '/achats/bons-commande'],
            ['libelle' => 'Liste des achats', 'href' => '/achats/liste'],
            ['libelle' => 'Ajouter un achat', 'href' => '/achats/liste/nouveau'],
            ['libelle' => "Retours d'achat", 'href' => '/achats/retours'],
        ]],
        ['libelle' => 'Gestion des ventes', 'href' => '/ventes', 'icone' => 'recu', 'roles' => ROLES_GESTION, 'enfants' => [
            ['libelle' => 'Devis', 'href' => '/ventes/devis'],
            ['libelle' => 'Commandes client', 'href' => '/ventes/commandes'],
            ['libelle' => 'Bons de livraison', 'href' => '/ventes/bons-livraison'],
            ['libelle' => 'Factures', 'href' => '/ventes/factures'],
            ['libelle' => 'Avoirs & retours', 'href' => '/ventes/avoirs'],
            ['libelle' => 'Gestion des livraisons', 'href' => '/ventes/livraisons'],
            ['libelle' => 'Échéances', 'href' => '/ventes/echeances'],
        ]],
        // L'atelier : chaque poste n'y voit que ce qu'il a à faire.
        ['libelle' => 'Imprimerie', 'href' => '/imprimerie', 'icone' => 'imprimante', 'roles' => ROLES_IMPRIMERIE, 'enfants' => [
            ["libelle" => "Tableau de l'atelier", 'href' => '/imprimerie', 'roles' => ROLES_IMPRIMERIE],
            ['libelle' => 'Ordres de travail', 'href' => '/imprimerie/travaux'],
            ['libelle' => 'Nouveau travail', 'href' => '/imprimerie/travaux/nouveau', 'roles' => ROLES_IMPRIMERIE_GESTION],
            ['libelle' => 'Comptoir impression', 'href' => '/imprimerie/comptoir', 'roles' => ROLES_IMPRIMERIE_GESTION],
            ['libelle' => 'Consommation & chutes', 'href' => '/imprimerie/consommations', 'roles' => ['ADMIN', 'MANAGER']],
            ['libelle' => 'Supports & prestations', 'href' => '/imprimerie/articles', 'roles' => ['ADMIN', 'MANAGER']],
            ['libelle' => 'Machines', 'href' => '/imprimerie/machines', 'roles' => ['ADMIN', 'MANAGER']],
            ['libelle' => 'Rapport matière', 'href' => '/imprimerie/rapport', 'roles' => ROLES_SYNTHESE],
        ]],
        ['libelle' => 'Chèques / Effets', 'href' => '/effets', 'icone' => 'cheque', 'roles' => ROLES_GESTION, 'enfants' => [
            ['libelle' => 'Tableau de bord', 'href' => '/effets'],
            ['libelle' => 'Tous les chèques', 'href' => '/effets/cheques'],
            ['libelle' => 'Tous les effets', 'href' => '/effets/effets'],
            ['libelle' => 'Rapport chèque / effet', 'href' => '/effets/rapport'],
        ]],
        ['libelle' => 'Inventaire physique', 'href' => '/inventaire', 'icone' => 'presse', 'roles' => ROLES_GESTION],
        ['libelle' => 'Documentation', 'href' => '/documentation', 'icone' => 'docs', 'roles' => ROLES_GESTION],
        ['libelle' => 'Gestion des dépenses', 'href' => '/depenses', 'icone' => 'portefeuille', 'roles' => ROLES_GESTION, 'enfants' => [
            ['libelle' => 'Liste des dépenses', 'href' => '/depenses'],
            ['libelle' => 'Ajouter une dépense', 'href' => '/depenses/nouvelle'],
            ['libelle' => 'Consommation de stock', 'href' => '/depenses/consommation'],
            ['libelle' => 'Catégories de dépenses', 'href' => '/depenses/categories'],
        ]],
        ['libelle' => 'Rapports', 'href' => '/rapports', 'icone' => 'graphique', 'roles' => ROLES_SYNTHESE],
        ['libelle' => 'Catalogue', 'href' => '/catalogue', 'icone' => 'qr', 'roles' => ROLES_GESTION],
        ['libelle' => 'Paramètres', 'href' => '/parametres', 'icone' => 'reglages', 'roles' => ['ADMIN', 'MANAGER'], 'enfants' => [
            ['libelle' => 'Société et lieux', 'href' => '/parametres'],
            ['libelle' => 'Sauvegarde des données', 'href' => '/parametres/sauvegarde', 'roles' => ['ADMIN']],
        ]],
    ];
}

/**
 * Page d'accueil du rôle : le caissier n'ayant pas accès au tableau de bord,
 * l'y renvoyer (connexion, redirections de garde) le ferait tourner en rond.
 * L'atelier, lui, ouvre sur son tableau.
 */
function accueilPour(string $role): string
{
    if (in_array($role, ROLES_ATELIER, true)) return '/imprimerie';
    return in_array($role, ROLES_SYNTHESE, true) ? '/tableau-de-bord' : '/caisse';
}

function navigationPour(string $role): array
{
    $entrees = [];
    foreach (navigation() as $entree) {
        if (isset($entree['roles']) && !in_array($role, $entree['roles'], true)) continue;

        if (isset($entree['enfants'])) {
            $entree['enfants'] = array_values(array_filter(
                $entree['enfants'],
                fn($enfant) => !isset($enfant['roles']) || in_array($role, $enfant['roles'], true),
            ));

            // Un menu dont tous les sous-écrans sont interdits disparaît : sans
            // cela le gabarit le rendrait en lien direct vers son `href`, qui
            // est justement l'écran auquel ce rôle n'a pas droit.
            if (!$entree['enfants']) continue;
        }

        $entrees[] = $entree;
    }
    return $entrees;
}
