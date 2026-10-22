<?php
/**
 * Demande d'accès — formulaire public.
 *
 * Rien n'est installé ici : la demande entre au registre avec le statut
 * « DEMANDE ». C'est la console qui décide, et l'acceptation seule crée la base
 * de la société et démarre l'essai.
 */

if (reglage('inscription_ouverte') !== '1') {
    rediriger('/login', "Les inscriptions sont momentanément fermées. Contactez " . nomPlateforme() . '.', 'info');
}

$erreur = null;
$envoye = false;

$formules = abonnements();

$valeurs = [
    'nom' => '', 'contact_nom' => '', 'contact_telephone' => '',
    'contact_email' => '', 'ville' => '', 'activite' => '', 'message' => '',
    // Formule envisagée : elle n'engage à rien, l'essai démarre de toute
    // façon. Elle dit simplement à la plateforme ce que le client vise.
    'abonnement' => '1a',
];

if (estPost()) {
    foreach (array_keys($valeurs) as $cle) $valeurs[$cle] = (string) champ($cle, '');

    $formule = abonnement($valeurs['abonnement']);
    $code = codeSociete($valeurs['nom']);

    // Le code portant 24 chiffres tirés au sort, deux sociétés homonymes ne se
    // gênent plus. Reste à intercepter le double envoi du même formulaire.
    $enDouble = un(
        "SELECT id FROM saas_societe
          WHERE statut = 'DEMANDE' AND nom = ? AND contact_telephone = ?
            AND created_at > ?",
        [$valeurs['nom'], $valeurs['contact_telephone'],
         (new DateTime('-1 day'))->format('Y-m-d H:i:s.v')],
    );

    if ($valeurs['nom'] === '' || $valeurs['contact_nom'] === '' || $valeurs['contact_telephone'] === '') {
        $erreur = 'Nom de la société, responsable et téléphone sont requis.';
    } elseif ($enDouble) {
        $erreur = 'Votre demande nous est déjà parvenue — nous revenons vers vous très vite.';
    } else {
        $id = inserer('saas_societe', [
            'nom'                => $valeurs['nom'],
            'code'               => $code,
            'statut'             => 'DEMANDE',
            'formule'            => 'ESSAI',
            'contact_nom'        => $valeurs['contact_nom'],
            'contact_telephone'  => $valeurs['contact_telephone'],
            'contact_email'      => ouNull($valeurs['contact_email']),
            'ville'              => ouNull($valeurs['ville']),
            'activite'           => ouNull($valeurs['activite']),
            'message'            => ouNull($valeurs['message']),
            'abonnement_choisi'  => $formule ? $valeurs['abonnement'] : null,
            'abonnement_montant' => $formule ? $formule['prix'] : null,
        ]);

        journaliser('demande', $id, 'Demande reçue de ' . $valeurs['contact_nom'] . '.'
            . ($formule ? ' Formule envisagée : ' . libelleAbonnement($valeurs['abonnement']) . '.' : ''));
        $envoye = true;
    }
}

pageNue('Demander un accès', function () use ($erreur, $envoye, $valeurs, $formules) { ?>
<div class="min-h-screen bg-marine-950 px-4 py-12">
  <div class="mx-auto max-w-2xl">
    <div class="mb-6 text-center">
      <span class="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-marine-500 text-2xl font-bold text-white">O</span>
      <h1 class="text-2xl font-bold text-white">Demander un accès</h1>
      <p class="mt-2 text-sm text-slate-400">
        <?= e(dureeEssai()) ?> jours d'essai à l'ouverture de votre espace — sans limite d'utilisation.
      </p>
    </div>

    <?php if ($envoye): ?>
      <div class="rounded-2xl bg-white p-8 text-center shadow-xl">
        <span class="mx-auto mb-4 grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700">
          <?= icone('coche', 'size-6') ?>
        </span>
        <h2 class="text-lg font-semibold text-slate-900">Demande envoyée</h2>
        <p class="mt-2 text-sm text-slate-600">
          Nous examinons votre dossier. Dès qu'il est accepté, vous recevez votre
          <strong>code société</strong>, votre identifiant et votre mot de passe
          <?php if (reglage('contact_plateforme') !== ''): ?>
            au <?= e(reglage('contact_plateforme')) ?>
          <?php endif; ?>.
        </p>
        <p class="mt-6"><?= boutonLien('/login', 'Retour à la connexion', ['variante' => 'secondaire']) ?></p>
      </div>
    <?php else: ?>
      <form method="post" class="space-y-5 rounded-2xl bg-white p-6 shadow-xl">
        <?= champCsrf() ?>
        <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

        <?= champBloc('Nom de la société', saisie('nom', $valeurs['nom'], ['attrs' => ['required' => true]]),
            ['obligatoire' => true, 'aide' => 'Tel qu\'il apparaîtra sur vos factures.']) ?>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <?= champBloc('Responsable', saisie('contact_nom', $valeurs['contact_nom'], ['attrs' => ['required' => true]]),
              ['obligatoire' => true]) ?>
          <?= champBloc('Téléphone', saisie('contact_telephone', $valeurs['contact_telephone'], [
              'type' => 'tel', 'attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('E-mail', saisie('contact_email', $valeurs['contact_email'], ['type' => 'email'])) ?>
          <?= champBloc('Ville', saisie('ville', $valeurs['ville'])) ?>
        </div>

        <?= champBloc('Activité', saisie('activite', $valeurs['activite']),
            ['aide' => 'Épicerie, quincaillerie, prêt-à-porter, négoce…']) ?>

        <div>
          <p class="mb-1.5 text-sm font-medium text-slate-700">Formule qui vous intéresse</p>
          <p class="mb-3 text-xs text-slate-500">
            Rien à payer maintenant : vos <?= e(dureeEssai()) ?> jours d'essai commencent dès l'ouverture
            de votre espace. Cette réponse nous dit simplement vers quoi vous vous orientez.
          </p>

          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <?php foreach ($formules as $cle => $formule): $choisie = $valeurs['abonnement'] === $cle; ?>
              <label class="relative cursor-pointer">
                <input type="radio" name="abonnement" value="<?= e($cle) ?>" class="peer sr-only"<?= $choisie ? ' checked' : '' ?>>
                <span class="block rounded-xl p-3 text-center ring-1 ring-slate-200 transition-colors
                             peer-checked:bg-marine-50 peer-checked:ring-2 peer-checked:ring-marine-500 hover:bg-slate-50">
                  <span class="block text-sm font-medium text-slate-800"><?= e($formule['libelle']) ?></span>
                  <span class="mt-1 block text-lg font-bold text-marine-700"><?= e(montant($formule['prix'])) ?></span>
                  <?php if ($formule['economie'] > 0): ?>
                    <span class="mt-1 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
                      −<?= (int) $formule['economie'] ?> %
                    </span>
                  <?php else: ?>
                    <span class="mt-1 block text-[11px] text-slate-400">tarif de base</span>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <p class="mt-2 text-xs text-slate-500">
            Toutes les formules donnent le même logiciel, sans limite d'utilisateurs, d'articles ni
            de factures. Seule la durée change.
          </p>
        </div>

        <?= champBloc('Votre besoin (facultatif)', zoneTexte('message', $valeurs['message'], ['lignes' => 4])) ?>

        <?= bouton('Envoyer la demande', ['classe' => 'w-full']) ?>

        <p class="text-center text-xs text-slate-500">
          Vos données restent dans une base qui n'appartient qu'à votre société.
        </p>
      </form>

      <p class="mt-6 text-center text-sm text-slate-400">
        Déjà client ? <a href="<?= e(u('/login')) ?>" class="font-medium text-white hover:underline">Se connecter</a>
      </p>
    <?php endif; ?>
  </div>
</div>
<?php }, 'min-h-full bg-marine-950');
