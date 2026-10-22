<?php
/**
 * Console de la plateforme : session du gestionnaire et ouverture des espaces.
 *
 * Deux sessions cohabitent dans le même cookie : `$_SESSION['console']` (le
 * gestionnaire de la plateforme) et `$_SESSION['outech']` (l'utilisateur d'une
 * société). Ouvrir l'espace d'un client depuis la console pose la seconde sans
 * effacer la première — le bandeau de retour reste disponible.
 */

/** Session console en cours, ou null. */
function sessionConsole(): ?array
{
    return $_SESSION['console'] ?? null;
}

function ouvrirSessionConsole(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['console'] = [
        'admin_id' => $admin['id'],
        'nom'      => $admin['nom'],
        'username' => $admin['username'],
    ];
}

function fermerSessionConsole(): void
{
    unset($_SESSION['console']);
    session_regenerate_id(true);
}

/** Session console obligatoire. */
function exigerConsole(): array
{
    $session = sessionConsole();
    if (!$session) rediriger('/console/login');
    return $session;
}

/**
 * Ouvre l'espace d'une société depuis la console, sous son compte
 * administrateur. La session porte `console => true` : le bandeau le rappelle
 * en permanence, et le contrôle d'abonnement est levé (un espace suspendu doit
 * justement rester consultable par la plateforme).
 */
function ouvrirEspace(array $societe): void
{
    exigerConsole();

    if (empty($societe['base'])) {
        rediriger('/console/societes/' . $societe['id'], "Cette société n'a pas encore d'espace.", 'erreur');
    }

    $ouverture = dansBase($societe['base'], function () {
        $utilisateur = un("SELECT * FROM utilisateur WHERE role = 'ADMIN' AND actif = 1
                           ORDER BY created_at LIMIT 1")
            ?: un('SELECT * FROM utilisateur WHERE actif = 1 ORDER BY created_at LIMIT 1');
        if (!$utilisateur) return null;

        $lieuId = valeur('SELECT lieu_id FROM utilisateur_lieu WHERE user_id = ? LIMIT 1', [$utilisateur['id']])
            ?: valeur('SELECT id FROM lieu_affaire WHERE actif = 1 ORDER BY code LIMIT 1');

        return $lieuId ? ['utilisateur' => $utilisateur, 'lieu_id' => (string) $lieuId] : null;
    });

    if (!$ouverture) {
        rediriger('/console/societes/' . $societe['id'],
            "Aucun compte utilisable dans cet espace — réinitialisez le compte administrateur.", 'erreur');
    }

    ouvrirSession($ouverture['utilisateur'], $ouverture['lieu_id'], $societe, true);
    journaliser('ouverture-espace', $societe['id'], 'Espace ouvert depuis la console.');

    rediriger(accueilPour($ouverture['utilisateur']['role']));
}

/* ------------------------------------------------------------- navigation */

function navigationConsole(): array
{
    $attente = 0;
    try {
        $attente = (int) dansConsole(fn() => valeur(
            "SELECT COUNT(*) FROM saas_societe WHERE statut = 'DEMANDE'"));
    } catch (Throwable $e) {
        // Registre absent : le menu s'affiche quand même, sans pastille.
    }

    return [
        ['libelle' => 'Tableau de bord', 'href' => '/console',            'icone' => 'jauge'],
        ['libelle' => 'Demandes',        'href' => '/console/demandes',   'icone' => 'flux', 'pastille' => $attente],
        ['libelle' => 'Sociétés',        'href' => '/console/societes',   'icone' => 'magasin'],
        ['libelle' => 'Sauvegardes',     'href' => '/console/sauvegardes','icone' => 'telecharger'],
        ['libelle' => 'Journal',         'href' => '/console/journal',    'icone' => 'horloge'],
        ['libelle' => 'Réglages',        'href' => '/console/reglages',   'icone' => 'reglages'],
    ];
}
