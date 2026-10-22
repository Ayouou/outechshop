<?php
/** Connexion à la console de la plateforme. */

if (sessionConsole()) rediriger('/console');

$erreur = null;
$identifiant = '';
$aucunCompte = false;

try {
    $aucunCompte = (int) dansConsole(fn() => valeur('SELECT COUNT(*) FROM saas_admin')) === 0;
} catch (Throwable $e) {
    $aucunCompte = true;
}

if (estPost()) {
    $identifiant = (string) champ('username', '');
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

    $admin = $identifiant === '' ? null
        : dansConsole(fn() => un('SELECT * FROM saas_admin WHERE username = ? LIMIT 1', [$identifiant]));

    // Message unique : pas d'énumération de comptes.
    if (!$admin || !$admin['actif'] || !password_verify($motDePasse, $admin['mot_de_passe'])) {
        $erreur = 'Identifiants incorrects.';
    } else {
        dansConsole(fn() => modifier('saas_admin', $admin['id'], ['dernier_login' => maintenant()]));
        ouvrirSessionConsole($admin);
        journaliser('connexion-console', null, 'Connexion de ' . $admin['username'] . '.');
        rediriger('/console');
    }
}

$message = flash();

pageConsoleNue('Console', function () use ($erreur, $identifiant, $aucunCompte, $message) { ?>
<div class="grid min-h-screen place-items-center bg-slate-900 px-4 py-10">
  <div class="w-full max-w-sm">
    <div class="mb-6 text-center">
      <span class="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-amber-400 text-2xl font-bold text-slate-900">P</span>
      <h1 class="text-2xl font-bold text-white">Console de la plateforme</h1>
      <p class="mt-2 text-sm text-slate-400">Gestion des sociétés clientes et des abonnements</p>
    </div>

    <div class="rounded-2xl bg-white p-6 shadow-xl">
      <?php if ($message): ?><div class="mb-4"><?= alerte($message['message'], $message['ton']) ?></div><?php endif; ?>
      <?php if ($erreur): ?><div class="mb-4"><?= alerte(e($erreur), 'erreur') ?></div><?php endif; ?>

      <?php if ($aucunCompte): ?>
        <div class="mb-4"><?= alerte(
            'Aucun compte gestionnaire. <a class="font-medium underline" href="'
            . e(u('/console/installation')) . '">Installer la console</a>.', 'info') ?></div>
      <?php endif; ?>

      <form method="post" class="space-y-4">
        <?= champCsrf() ?>
        <?= champBloc('Identifiant', saisie('username', $identifiant, [
            'attrs' => ['required' => true, 'autofocus' => true, 'autocomplete' => 'username'],
        ])) ?>
        <?= champBloc('Mot de passe', saisie('mot_de_passe', '', [
            'type' => 'password', 'attrs' => ['required' => true, 'autocomplete' => 'current-password'],
        ])) ?>
        <button type="submit" class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
          Entrer
        </button>
      </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
      <a href="<?= e(u('/login')) ?>" class="hover:text-slate-300">Espace client →</a>
    </p>
  </div>
</div>
<?php }, 'min-h-full bg-slate-900');
