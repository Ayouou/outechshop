<?php
/** Console — réglages de la plateforme et comptes gestionnaires. */

$sessionAdmin = exigerConsole();

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', 'reglages');

    if ($action === 'reglages') {
        poserReglage('nom_plateforme', (string) champ('nom_plateforme', 'OUTECH360'));
        poserReglage('duree_essai', (string) max(1, (int) champ('duree_essai', 7)));
        poserReglage('max_societes', (string) max(0, (int) champ('max_societes', 30)));
        poserReglage('inscription_ouverte', champBool('inscription_ouverte') ? '1' : '0');
        poserReglage('contact_plateforme', (string) champ('contact_plateforme', ''));

        foreach (array_keys(ABONNEMENTS) as $cle) {
            poserReglage('prix_' . $cle, (string) max(0, champNombre('prix_' . $cle)));
        }

        journaliser('reglages', null, 'Réglages de la plateforme modifiés.');
        rediriger('/console/reglages', 'Réglages enregistrés.');
    }

    if ($action === 'motdepasse') {
        $actuel = (string) ($_POST['actuel'] ?? '');
        $nouveau = (string) ($_POST['nouveau'] ?? '');
        $admin = un('SELECT * FROM saas_admin WHERE id = ?', [$sessionAdmin['admin_id']]);

        if (!$admin || !password_verify($actuel, $admin['mot_de_passe'])) {
            $erreur = 'Mot de passe actuel incorrect.';
        } elseif (strlen($nouveau) < 8) {
            $erreur = 'Le nouveau mot de passe doit faire 8 caractères minimum.';
        } else {
            modifier('saas_admin', $admin['id'], ['mot_de_passe' => password_hash($nouveau, PASSWORD_BCRYPT)]);
            journaliser('mot-de-passe-console', null, 'Mot de passe du compte ' . $admin['username'] . ' changé.');
            rediriger('/console/reglages', 'Mot de passe modifié.');
        }
    }

    if ($action === 'gestionnaire') {
        $nom = (string) champ('nom', '');
        $username = (string) champ('username', '');
        $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

        if ($username === '' || strlen($motDePasse) < 8) {
            $erreur = 'Identifiant requis et mot de passe de 8 caractères minimum.';
        } elseif (un('SELECT id FROM saas_admin WHERE username = ?', [$username])) {
            $erreur = 'Cet identifiant est déjà pris.';
        } else {
            inserer('saas_admin', [
                'nom'          => $nom ?: $username,
                'username'     => $username,
                'mot_de_passe' => password_hash($motDePasse, PASSWORD_BCRYPT),
                'actif'        => 1,
            ]);
            journaliser('gestionnaire', null, 'Compte gestionnaire ' . $username . ' créé.');
            rediriger('/console/reglages', 'Gestionnaire ajouté.');
        }
    }
}

$admins = tous('SELECT * FROM saas_admin ORDER BY created_at');

pageConsole('Réglages', function () use ($admins, $erreur, $sessionAdmin) {
    echo enTetePage('Réglages de la plateforme');
    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    ?>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <form method="post" class="space-y-5">
        <?= champCsrf() ?><input type="hidden" name="_action" value="reglages">

        <?= carteDebut('Service') ?>
          <div class="space-y-4">
            <?= champBloc('Nom de la plateforme', saisie('nom_plateforme', reglage('nom_plateforme')),
                ['aide' => 'Affiché aux clients sur la page de connexion et la demande d\'accès.']) ?>
            <?= champBloc('Durée de l\'essai (jours)', saisie('duree_essai', reglage('duree_essai'), [
                'type' => 'number', 'attrs' => ['min' => 1, 'max' => 365]]),
                ['aide' => 'Appliquée à chaque nouvelle acceptation. Les essais en cours ne bougent pas.']) ?>
            <?= champBloc('Nombre maximum de sociétés', saisie('max_societes', reglage('max_societes'), [
                'type' => 'number', 'attrs' => ['min' => 0]]),
                ['aide' => '0 = sans plafond. Ce garde-fou ne limite en rien ce que fait chaque société.']) ?>
            <?= champBloc('Contact affiché aux clients', saisie('contact_plateforme', reglage('contact_plateforme')),
                ['aide' => 'Téléphone ou e-mail montré quand un essai se termine.']) ?>
            <?= caseACocher('inscription_ouverte', reglage('inscription_ouverte') === '1',
                'Ouvrir le formulaire public de demande d\'accès') ?>
            <p class="text-xs text-slate-500">
              Fermé, le formulaire <code>/demande</code> renvoie un message d'attente : seule la console
              peut alors enregistrer une nouvelle société.
            </p>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Tarifs', ['sous_titre' => 'Affichés au prospect, et appliqués au passage en Pro']) ?>
          <div class="space-y-3">
            <?php foreach (abonnements() as $cle => $formule): ?>
              <div class="flex items-center gap-3">
                <span class="w-20 shrink-0 text-sm text-slate-700"><?= e($formule['libelle']) ?></span>
                <?= saisie('prix_' . $cle, nombre($formule['prix']), [
                    'type' => 'number', 'classe' => 'max-w-[9rem]',
                    'attrs' => ['min' => 0, 'step' => '1'],
                ]) ?>
                <!-- La console n'est branchée sur aucune base cliente : sa
                     devise ne se lit pas dans une table `societe`. -->
                <span class="text-sm text-slate-400">dhs</span>
                <span class="ml-auto text-right text-xs text-slate-500">
                  <?= e(montant($formule['par_mois'])) ?> / mois
                  <?php if ($formule['economie'] > 0): ?>
                    <span class="text-emerald-600">(−<?= (int) $formule['economie'] ?> %)</span>
                  <?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="mt-3 text-xs text-slate-500">
            Le pourcentage se calcule face au tarif mensuel : baissez celui-ci et toutes les
            remises affichées suivent.
          </p>
        <?= carteFin() ?>

        <div class="flex justify-end"><?= bouton('Enregistrer les réglages') ?></div>
      </form>

      <div class="space-y-5">
        <?= carteDebut('Aucune limite pour les sociétés') ?>
          <p class="text-sm text-slate-600">
            Les espaces clients ne sont bornés ni en utilisateurs, ni en articles, ni en documents.
            Le seul contrôle exercé par la plateforme est celui de l'accès : essai, formule Pro,
            suspension. Ce que fait une société à l'intérieur de son espace ne regarde qu'elle.
          </p>
        <?= carteFin() ?>

        <?= carteDebut('Mon mot de passe') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?><input type="hidden" name="_action" value="motdepasse">
            <?= champBloc('Mot de passe actuel', saisie('actuel', '', ['type' => 'password', 'attrs' => ['required' => true]])) ?>
            <?= champBloc('Nouveau mot de passe', saisie('nouveau', '', [
                'type' => 'password', 'attrs' => ['required' => true, 'minlength' => 8]])) ?>
            <div class="flex justify-end"><?= bouton('Changer', ['variante' => 'secondaire']) ?></div>
          </form>
        <?= carteFin() ?>

        <?= carteDebut('Gestionnaires de la plateforme', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Nom', 'Identifiant', 'Dernière connexion']) ?>
            <?php foreach ($admins as $a): ?>
              <tr>
                <td class="<?= CLASSES_TD ?>">
                  <?= e($a['nom']) ?>
                  <?= $a['id'] === $sessionAdmin['admin_id'] ? ' ' . badge('vous', 'bleu') : '' ?>
                </td>
                <td class="<?= CLASSES_TD ?> font-mono text-xs"><?= e($a['username']) ?></td>
                <td class="<?= CLASSES_TD ?>"><?= $a['dernier_login'] ? e(dateHeureFr($a['dernier_login'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          <?= tableauFin() ?>

          <form method="post" class="space-y-4 border-t border-slate-100 p-5">
            <?= champCsrf() ?><input type="hidden" name="_action" value="gestionnaire">
            <p class="text-sm font-medium text-slate-800">Ajouter un gestionnaire</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <?= champBloc('Nom', saisie('nom', '')) ?>
              <?= champBloc('Identifiant', saisie('username', '', ['attrs' => ['required' => true]])) ?>
              <?= champBloc('Mot de passe', saisie('mot_de_passe', '', [
                  'type' => 'password', 'attrs' => ['required' => true, 'minlength' => 8]])) ?>
            </div>
            <div class="flex justify-end"><?= bouton('Ajouter', ['variante' => 'secondaire']) ?></div>
          </form>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
