<?php
/**
 * Session applicative et contrôle d'accès.
 *
 * L'application d'origine signait un JWT dans un cookie ; en PHP la session
 * native fait le même travail avec moins de pièces : le cookie ne porte qu'un
 * identifiant opaque, les données restent sur le serveur.
 */

const DUREE_SESSION = 7 * 24 * 60 * 60; // 7 jours, comme le JWT d'origine

function demarrerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name('outech_session');
    session_set_cookie_params([
        'lifetime' => DUREE_SESSION,
        'path'     => base() ?: '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // `secure` seulement en HTTPS : imposé en clair, le cookie ne serait
        // jamais posé et l'utilisateur reviendrait indéfiniment sur le login.
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/**
 * Session courante : `user_id`, `nom`, `username`, `role`, `lieu_id`, et la
 * société à laquelle appartient l'utilisateur (`societe_id`, `societe_code`).
 * C'est elle qui décide de la base attaquée par toute la requête.
 */
function sessionCourante(): ?array
{
    return $_SESSION['outech'] ?? null;
}

/**
 * Ouvre la session d'un utilisateur dans l'espace d'une société.
 * `$console` marque une session ouverte depuis la console de la plateforme :
 * le bandeau de retour s'affiche et le contrôle d'abonnement est levé.
 */
function ouvrirSession(array $utilisateur, string $lieuId, array $societe, bool $console = false): void
{
    session_regenerate_id(true);
    $_SESSION['outech'] = [
        'user_id'      => $utilisateur['id'],
        'nom'          => $utilisateur['nom'],
        'username'     => $utilisateur['username'],
        'role'         => $utilisateur['role'],
        'lieu_id'      => $lieuId,
        'societe_id'   => $societe['id'],
        'societe_code' => $societe['code'],
        'societe_nom'  => $societe['nom'],
        'console'      => $console,
    ];
}

/** La session en cours a-t-elle été ouverte depuis la console ? */
function enEspaceConsole(): bool
{
    return !empty(sessionCourante()['console']);
}

/**
 * Ferme la session en cours.
 *
 * L'ancienne session est détruite et remplacée par une session vide portant un
 * nouvel identifiant, plutôt qu'effacée par un cookie expiré : la page suivante
 * (message de confirmation, jeton CSRF du formulaire de connexion) a besoin
 * d'un cookie valide pour fonctionner.
 */
function fermerSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) demarrerSession();

    // La session console, si elle existe, survit : refermer l'espace d'un
    // client ne doit pas déconnecter le gestionnaire de la plateforme.
    $console = $_SESSION['console'] ?? null;
    $_SESSION = $console ? ['console' => $console] : [];
    session_regenerate_id(true);
}

/** Session obligatoire : redirige vers /login si absente. */
function exigerSession(): array
{
    $session = sessionCourante();
    if (!$session) rediriger('/login');
    return $session;
}

/**
 * Chemins ouverts aux postes de l'atelier (designer, machiniste).
 *
 * Tout le reste du logiciel leur est fermé : ventes, achats, caisse, stock,
 * dépenses. Cacher les entrées du menu ne suffit pas — une adresse tapée à la
 * main y mènerait tout de même.
 */
const CHEMINS_ATELIER = ['/', '/imprimerie', '/deconnexion', '/abonnement', '/api/images'];

/**
 * Referme le logiciel sur l'atelier quand le rôle en est un.
 *
 * Appelée une fois par requête depuis `index.php`, avant la page : c'est la
 * barrière, le menu n'en est que le reflet.
 */
function verifierPortee(): void
{
    $session = sessionCourante();
    if (!$session || !in_array($session['role'], ROLES_ATELIER, true)) return;

    $chemin = cheminDemande();
    foreach (CHEMINS_ATELIER as $ouvert) {
        if ($chemin === $ouvert) return;
        // La racine n'ouvre qu'elle-même : traitée comme un préfixe, elle
        // laisserait passer toutes les adresses du logiciel.
        if ($ouvert !== '/' && str_starts_with($chemin, $ouvert . '/')) return;
    }

    rediriger('/imprimerie', 'Accès réservé.', 'erreur');
}

/**
 * Session + vérification de rôle.
 * Renvoi vers l'accueil du rôle : celui d'un caissier n'est pas le tableau de
 * bord, qui lui est justement fermé.
 */
function exigerRole(string ...$roles): array
{
    $session = exigerSession();
    if (!in_array($session['role'], $roles, true)) {
        rediriger(accueilPour($session['role']), "Accès réservé.", 'erreur');
    }
    return $session;
}

/** Utilisateur complet (avec ses lieux), chargé une fois par requête. */
function utilisateurCourant(): ?array
{
    static $utilisateur = null;
    static $charge = false;
    if ($charge) return $utilisateur;
    $charge = true;

    $session = sessionCourante();
    if (!$session) return null;

    $utilisateur = un('SELECT * FROM utilisateur WHERE id = ?', [$session['user_id']]);
    if ($utilisateur) {
        $utilisateur['lieux'] = tous(
            'SELECT l.* FROM utilisateur_lieu ul JOIN lieu_affaire l ON l.id = ul.lieu_id
             WHERE ul.user_id = ? ORDER BY l.nom',
            [$session['user_id']],
        );
    }
    return $utilisateur;
}

/** Lieu d'affaires de la session. */
function lieuCourant(): ?array
{
    static $lieu = null;
    static $charge = false;
    if ($charge) return $lieu;
    $charge = true;

    $session = sessionCourante();
    if (!$session) return null;
    return $lieu = un('SELECT * FROM lieu_affaire WHERE id = ?', [$session['lieu_id']]);
}

/** Société (ligne unique) — devise, TVA par défaut, en-tête des impressions. */
function societe(): array
{
    static $societe = null;
    if ($societe !== null) return $societe;

    $societe = un('SELECT * FROM societe ORDER BY created_at LIMIT 1') ?? [
        'nom' => 'OUTECH', 'devise' => 'dhs', 'tva_defaut' => '20',
    ];
    return $societe;
}

function devise(): string
{
    return societe()['devise'] ?: 'dhs';
}

/* --------------------------------------------------------------------- CSRF */

function jetonCsrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/** Champ caché à placer dans chaque formulaire. */
function champCsrf(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(jetonCsrf()) . '">';
}

/** Refuse un POST dont le jeton ne correspond pas (formulaire étranger). */
function verifierCsrf(): void
{
    if (!estPost()) return;
    $envoye = $_POST['_csrf'] ?? '';
    if (!is_string($envoye) || !hash_equals($_SESSION['csrf'] ?? '', $envoye)) {
        http_response_code(419);
        exit('Session expirée — rechargez la page et recommencez.');
    }
}
