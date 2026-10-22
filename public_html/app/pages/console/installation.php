<?php
/**
 * Mise en service de la plateforme : base « console » et premier compte
 * gestionnaire. La page se referme dès qu'un compte existe.
 */

$installee = false;
try {
    $installee = (int) dansConsole(fn() => valeur('SELECT COUNT(*) FROM saas_admin')) > 0;
} catch (Throwable $e) {
    // Base console absente : c'est précisément ce que cette page installe.
}

if ($installee) rediriger('/console/login', 'La console est déjà installée.', 'info');

$erreur = null;
$valeurs = ['plateforme' => 'OUTECH360', 'nom' => 'Gestionnaire', 'username' => 'plateforme'];

if (estPost()) {
    foreach (array_keys($valeurs) as $cle) $valeurs[$cle] = (string) champ($cle, $valeurs[$cle]);
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

    if ($valeurs['username'] === '' || strlen($motDePasse) < 8) {
        $erreur = 'Identifiant requis, et mot de passe de 8 caractères minimum.';
    } else {
        try {
            installerConsole();

            dansConsole(function () use ($valeurs, $motDePasse) {
                inserer('saas_admin', [
                    'nom'          => $valeurs['nom'] ?: 'Gestionnaire',
                    'username'     => $valeurs['username'],
                    'mot_de_passe' => password_hash($motDePasse, PASSWORD_BCRYPT),
                    'actif'        => 1,
                ]);
            });

            foreach (REGLAGES_DEFAUT as $cle => $defaut) poserReglage($cle, $defaut);
            poserReglage('nom_plateforme', $valeurs['plateforme'] ?: 'OUTECH360');

            rediriger('/console/login', 'Console installée — connectez-vous.');
        } catch (Throwable $e) {
            $erreur = $e->getMessage();
        }
    }
}

pageConsoleNue('Installation de la console', function () use ($erreur, $valeurs) { ?>
<div class="mx-auto max-w-lg px-4 py-12">
  <div class="mb-6 text-center">
    <span class="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-amber-400 text-xl font-bold text-slate-900">P</span>
    <h1 class="text-2xl font-semibold text-slate-900">Mise en service de la plateforme</h1>
    <p class="mt-1 text-sm text-slate-500">
      Crée la base <code><?= e(baseConsole()) ?></code> et votre compte gestionnaire.
    </p>
  </div>

  <?php if ($erreur): ?><div class="mb-4"><?= alerte(e($erreur), 'erreur') ?></div><?php endif; ?>

  <form method="post" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
    <?= champCsrf() ?>
    <?= champBloc('Nom de la plateforme', saisie('plateforme', $valeurs['plateforme']),
        ['aide' => 'Affiché aux clients sur la page de connexion.']) ?>
    <hr class="border-slate-100">
    <?= champBloc('Votre nom', saisie('nom', $valeurs['nom'])) ?>
    <?= champBloc('Identifiant', saisie('username', $valeurs['username'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
    <?= champBloc('Mot de passe', saisie('mot_de_passe', '', [
        'type' => 'password', 'attrs' => ['required' => true, 'minlength' => 8],
    ]), ['obligatoire' => true, 'aide' => '8 caractères minimum — ce compte administre toutes les sociétés.']) ?>
    <button type="submit" class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
      Installer la console
    </button>
  </form>

  <p class="mt-6 text-center text-xs text-slate-500">
    Les données commerciales des clients ne transitent jamais par cette base.
  </p>
</div>
<?php });
