<?php
/**
 * Écran de connexion.
 *
 * Trois champs : le **code société**, qui désigne l'espace (donc la base) à
 * ouvrir, puis l'identifiant et le mot de passe, vérifiés dans cette base et
 * nulle part ailleurs. Le code est mémorisé dans un cookie et prérempli par le
 * lien `?e=<code>` remis au client, si bien qu'on ne le tape qu'une fois.
 */

$session = sessionCourante();
if ($session) rediriger(accueilPour($session['role']));

const COOKIE_CODE = 'outech_societe';

$erreur = null;
$identifiant = '';
$code = (string) (param('e', '') ?: ($_COOKIE[COOKIE_CODE] ?? ''));
$suite = (string) param('suite', '');

if (estPost()) {
    $code = (string) champ('code', '');
    $identifiant = (string) champ('username', '');
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
    $suite = (string) champ('suite', '');

    $societe = societeParCode($code);

    if ($code === '' || $identifiant === '' || $motDePasse === '') {
        $erreur = 'Code société, identifiant et mot de passe sont requis.';
    } elseif (!$societe || empty($societe['base'])) {
        // Volontairement vague : le code d'une société ne se devine pas par
        // tâtonnement sur ce formulaire.
        $erreur = 'Code société inconnu.';
    } elseif (!accesAutorise($societe)) {
        $erreur = match (situation($societe)) {
            'ESSAI_EXPIRE' => "La période d'essai de « " . $societe['nom'] . " » est terminée. "
                . 'Contactez ' . nomPlateforme() . ' pour activer l\'abonnement.',
            'PRO_EXPIRE'   => "L'abonnement de « " . $societe['nom'] . ' » est échu. '
                . 'Contactez ' . nomPlateforme() . ' pour le renouveler.',
            'SUSPENDUE'    => 'Cet espace est momentanément suspendu.',
            default        => 'Cet espace n\'est pas encore ouvert.',
        };
    } else {
        // À partir d'ici, toutes les requêtes portent sur la base de cette
        // société — et sur aucune autre.
        $utilisateur = dansBase($societe['base'],
            fn() => un('SELECT * FROM utilisateur WHERE username = ? LIMIT 1', [$identifiant]));

        // Message identique dans tous les cas : pas d'énumération de comptes.
        if (!$utilisateur || !$utilisateur['actif'] || !password_verify($motDePasse, $utilisateur['mot_de_passe'])) {
            $erreur = 'Identifiants incorrects.';
        } else {
            $lieuId = dansBase($societe['base'], function () use ($utilisateur) {
                return valeur('SELECT lieu_id FROM utilisateur_lieu WHERE user_id = ? LIMIT 1', [$utilisateur['id']])
                    ?: valeur('SELECT id FROM lieu_affaire WHERE actif = 1 ORDER BY code LIMIT 1');
            });

            if (!$lieuId) {
                $erreur = "Aucun lieu d'affaires n'est configuré. Contactez l'administrateur.";
            } else {
                dansBase($societe['base'],
                    fn() => modifier('utilisateur', $utilisateur['id'], ['dernier_login' => maintenant()]));

                modifier('saas_societe', $societe['id'], ['derniere_connexion' => maintenant()]);

                // Le code revient tout seul à la prochaine visite.
                setcookie(COOKIE_CODE, $societe['code'], [
                    'expires'  => time() + 365 * 24 * 60 * 60,
                    'path'     => base() ?: '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                ouvrirSession($utilisateur, (string) $lieuId, $societe);
                rediriger(str_starts_with($suite, '/') ? $suite : accueilPour($utilisateur['role']));
            }
        }
    }
}

// Première mise en service de la plateforme : aucun gestionnaire en base.
$consoleAInstaller = false;
$registreAbsent = false;
try {
    $consoleAInstaller = (int) valeur('SELECT COUNT(*) FROM saas_admin') === 0;
} catch (Throwable $e) {
    $registreAbsent = true;
}

$inscriptionOuverte = reglage('inscription_ouverte') === '1';
$message = flash();

pageNue('Connexion', function () use (
    $erreur, $identifiant, $code, $suite, $message, $consoleAInstaller, $registreAbsent, $inscriptionOuverte
) { ?>
<div class="grid min-h-screen place-items-center bg-marine-950 px-4 py-10">
  <div class="w-full max-w-sm">
    <div class="mb-6 text-center">
      <span class="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-marine-500 text-2xl font-bold text-white">O</span>
      <h1 class="text-3xl font-bold text-white">OUTECH<span class="text-amber-400">360</span></h1>
      <p class="mt-2 text-sm text-slate-400">La solution de gestion intelligente pour votre entreprise</p>
    </div>

    <div class="rounded-2xl bg-white p-6 shadow-xl">
      <div class="mb-5 text-center">
        <h2 class="text-lg font-semibold text-slate-900">Bienvenue</h2>
        <p class="text-sm text-slate-500">Connectez-vous à l'espace de votre société</p>
      </div>

      <?php if ($message): ?>
        <div class="mb-4"><?= alerte($message['message'], $message['ton']) ?></div>
      <?php endif; ?>

      <?php if ($erreur): ?>
        <div class="mb-4"><?= alerte(e($erreur), 'erreur') ?></div>
      <?php endif; ?>

      <?php if ($registreAbsent): ?>
        <div class="mb-4"><?= alerte(
            'Le registre des sociétés est absent. <a class="font-medium underline" href="'
            . e(u('/console/installation')) . '">Installer la plateforme</a>.', 'erreur') ?></div>
      <?php elseif ($consoleAInstaller): ?>
        <div class="mb-4"><?= alerte(
            'Plateforme non installée. <a class="font-medium underline" href="'
            . e(u('/console/installation')) . '">Lancer l\'installation</a>.', 'info') ?></div>
      <?php endif; ?>

      <form method="post" class="space-y-4">
        <?= champCsrf() ?>
        <input type="hidden" name="suite" value="<?= e($suite) ?>">
        <?= champBloc('Code société', saisie('code', $code, [
            'classe' => 'font-mono text-[13px]',
            'attrs' => ['required' => true, 'autocapitalize' => 'off', 'spellcheck' => 'false',
                        'autocomplete' => 'off', 'placeholder' => 'nom-de-votre-societe-000000000000000000000000'],
        ]), ['obligatoire' => true,
             'aide' => 'Le code remis par ' . e(nomPlateforme()) . '. Collez-le : il est retenu pour vos prochaines visites.']) ?>
        <?= champBloc('Identifiant', saisie('username', $identifiant, [
            'attrs' => ['required' => true, 'autofocus' => $code !== '', 'autocomplete' => 'username'],
        ])) ?>
        <?= champBloc('Mot de passe', saisie('mot_de_passe', '', [
            'type' => 'password',
            'attrs' => ['required' => true, 'autocomplete' => 'current-password'],
        ])) ?>
        <?= bouton('Se connecter', ['classe' => 'w-full']) ?>
      </form>

      <?php if ($inscriptionOuverte): ?>
        <p class="mt-5 border-t border-slate-100 pt-4 text-center text-sm text-slate-500">
          Pas encore de compte ?
          <a href="<?= e(u('/demande')) ?>" class="font-medium text-marine-700 hover:underline">Demander un accès</a>
        </p>
      <?php endif; ?>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">© <?= date('Y') ?> OUTECH — Tous droits réservés</p>
  </div>
</div>
<?php }, 'min-h-full bg-marine-950');
