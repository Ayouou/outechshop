<?php
/**
 * Écran de fin d'essai / d'abonnement.
 *
 * L'utilisateur reste connecté — ses données sont intactes, simplement hors de
 * portée tant que l'accès n'est pas rétabli par la plateforme. Aucune donnée
 * n'est supprimée : c'est la promesse faite au client.
 */

$session = exigerSession();
$societe = societeSession();
if (!$societe) rediriger('/login');

// Accès rétabli entre-temps : on rend la main à l'application.
if (accesAutorise($societe)) rediriger(accueilPour($session['role']));

$situation = situation($societe);
$contact = reglage('contact_plateforme');

$titres = [
    'ESSAI_EXPIRE' => "Votre période d'essai est terminée",
    'PRO_EXPIRE'   => 'Votre abonnement est arrivé à échéance',
    'SUSPENDUE'    => 'Votre espace est momentanément suspendu',
];

$textes = [
    'ESSAI_EXPIRE' => "Les " . dureeEssai() . " jours d'essai de « " . $societe['nom'] . " » sont écoulés. "
        . "Passez à la formule Pro pour rouvrir votre espace : rien n'a été perdu.",
    'PRO_EXPIRE'   => "L'abonnement de « " . $societe['nom'] . " » a pris fin le "
        . dateFr($societe['abonnement_fin']) . ". Renouvelez-le pour retrouver votre espace.",
    'SUSPENDUE'    => "L'accès à l'espace de « " . $societe['nom'] . " » a été suspendu. "
        . 'Contactez-nous pour régulariser la situation.',
];

pageNue('Abonnement', function () use ($societe, $situation, $contact, $titres, $textes, $session) { ?>
<div class="grid min-h-screen place-items-center bg-marine-950 px-4 py-10">
  <div class="w-full max-w-lg">
    <div class="rounded-2xl bg-white p-8 shadow-xl">
      <span class="mx-auto mb-5 grid size-14 place-items-center rounded-2xl bg-amber-100 text-amber-700">
        <?= icone('horloge', 'size-7') ?>
      </span>

      <h1 class="text-center text-xl font-semibold text-slate-900">
        <?= e($titres[$situation] ?? 'Accès momentanément indisponible') ?>
      </h1>
      <p class="mt-3 text-center text-sm text-slate-600">
        <?= e($textes[$situation] ?? "L'accès à votre espace est momentanément fermé.") ?>
      </p>

      <div class="mt-6 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900 ring-1 ring-emerald-200">
        <p class="font-medium">Vos données sont conservées.</p>
        <p class="mt-1">
          Articles, clients, factures, stock et règlements restent enregistrés dans la base réservée à
          votre société. Tout revient en l'état dès la réouverture de l'accès.
        </p>
      </div>

      <?php if ($contact !== ''): ?>
        <div class="mt-4 rounded-xl bg-slate-50 p-4 text-center text-sm ring-1 ring-slate-200">
          <p class="text-slate-500">Pour rouvrir votre espace</p>
          <p class="mt-1 text-base font-semibold text-slate-900"><?= e($contact) ?></p>
        </div>
      <?php endif; ?>

      <dl class="mt-6 space-y-1 text-sm">
        <?= ligneInfo('Société', $societe['nom']) ?>
        <?= ligneInfo('Formule', $societe['formule'] === 'PRO' ? 'Pro' : 'Essai') ?>
        <?= ligneInfo('Connecté en tant que', $session['nom']) ?>
      </dl>

      <p class="mt-4 mb-1.5 text-sm text-slate-500">Votre code société</p>
      <?= champCopiable($societe['code']) ?>

      <form method="post" action="<?= e(u('/deconnexion')) ?>" class="mt-6">
        <?= champCsrf() ?>
        <?= bouton('Se déconnecter', ['variante' => 'secondaire', 'classe' => 'w-full']) ?>
      </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">© <?= date('Y') ?> <?= e(nomPlateforme()) ?></p>
  </div>
</div>
<?= scriptCopie() ?>
<?php }, 'min-h-full bg-marine-950');
