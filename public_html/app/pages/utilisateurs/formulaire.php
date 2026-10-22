<?php
/** Création / modification d'un compte utilisateur (administrateur seulement). */

exigerRole('ADMIN');

$id = $params['id'] ?? null;
$utilisateur = null;
$lieuxAffectes = [];

if ($id) {
    $utilisateur = un('SELECT * FROM utilisateur WHERE id = ?', [$id]);
    if (!$utilisateur) introuvable('Utilisateur introuvable');
    $lieuxAffectes = colonne('SELECT lieu_id FROM utilisateur_lieu WHERE user_id = ?', [$id]);
}

$erreur = null;
$v = $utilisateur ?? [
    'nom' => '', 'username' => '', 'email' => '', 'telephone' => '',
    'role' => 'COMMERCIAL', 'actif' => 1,
];

if (estPost()) {
    $donnees = [
        'nom'       => (string) champ('nom', ''),
        'username'  => (string) champ('username', ''),
        'email'     => ouNull(champ('email')),
        'telephone' => ouNull(champ('telephone')),
        'role'      => isset(ROLES[champ('role')]) ? champ('role') : 'COMMERCIAL',
        'actif'     => champBool('actif') ? 1 : 0,
    ];
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
    $lieux = array_values(array_filter((array) ($_POST['lieux'] ?? [])));
    $v = array_merge($v, $donnees);
    $lieuxAffectes = $lieux;

    if (mb_strlen($donnees['nom']) < 2) {
        $erreur = 'Le nom est obligatoire';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,}$/', $donnees['username'])) {
        $erreur = "L'identifiant doit faire au moins 3 caractères (lettres, chiffres, point, tiret et souligné).";
    } elseif ($donnees['email'] && !filter_var($donnees['email'], FILTER_VALIDATE_EMAIL)) {
        $erreur = 'E-mail invalide';
    } elseif (!$utilisateur && strlen($motDePasse) < 6) {
        $erreur = 'Le mot de passe doit faire au moins 6 caractères.';
    } elseif ($utilisateur && $motDePasse !== '' && strlen($motDePasse) < 6) {
        $erreur = 'Le nouveau mot de passe doit faire au moins 6 caractères.';
    } else {
        try {
            transaction(function () use ($utilisateur, $donnees, $motDePasse, $lieux, &$id) {
                if ($motDePasse !== '') {
                    $donnees['mot_de_passe'] = password_hash($motDePasse, PASSWORD_BCRYPT);
                }

                if ($utilisateur) {
                    modifier('utilisateur', $utilisateur['id'], $donnees);
                    $id = $utilisateur['id'];
                } else {
                    $id = inserer('utilisateur', $donnees);
                }

                q('DELETE FROM utilisateur_lieu WHERE user_id = ?', [$id]);
                foreach ($lieux as $lieuId) {
                    inserer('utilisateur_lieu', ['user_id' => $id, 'lieu_id' => $lieuId]);
                }
            });

            rediriger('/utilisateurs', 'Utilisateur enregistré.');
        } catch (PDOException $e) {
            $erreur = 'Cet identifiant ou cet e-mail est déjà utilisé.';
        }
    }
}

$lieux = tous('SELECT id, nom, code FROM lieu_affaire WHERE actif = 1 ORDER BY nom');

page($utilisateur ? "Modifier l'utilisateur" : 'Nouvel utilisateur', function () use ($utilisateur, $v, $erreur, $lieux, $lieuxAffectes) {
    echo enTetePage(
        $utilisateur ? 'Modifier « ' . $utilisateur['nom'] . ' »' : 'Nouvel utilisateur',
        'Compte, rôle et lieux d\'affaires',
        boutonLien('/utilisateurs', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );
    ?>
    <div class="max-w-3xl">
      <?= carteDebut('Compte') ?>
        <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <?= champCsrf() ?>
          <?php if ($erreur) echo '<div class="sm:col-span-2">' . alerte(e($erreur), 'erreur') . '</div>'; ?>
          <?= champBloc('Nom complet', saisie('nom', $v['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('Identifiant', saisie('username', $v['username'], ['attrs' => ['required' => true]]), ['obligatoire' => true, 'aide' => 'Sert à la connexion']) ?>
          <?= champBloc('E-mail', saisie('email', $v['email'], ['type' => 'email'])) ?>
          <?= champBloc('Téléphone', saisie('telephone', $v['telephone'])) ?>
          <?= champBloc('Rôle', listeDeroulante('role', ROLES, $v['role'])) ?>
          <?= champBloc($utilisateur ? 'Nouveau mot de passe' : 'Mot de passe',
              saisie('mot_de_passe', '', ['type' => 'password', 'attrs' => $utilisateur ? [] : ['required' => true]]),
              ['aide' => $utilisateur ? 'Laisser vide pour le conserver' : '6 caractères minimum']) ?>

          <div class="sm:col-span-2">
            <p class="mb-1.5 text-sm font-medium text-slate-700">Lieux d'affaires</p>
            <div class="flex flex-wrap gap-3">
              <?php foreach ($lieux as $lieu): ?>
                <label class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-slate-200">
                  <input type="checkbox" name="lieux[]" value="<?= e($lieu['id']) ?>"
                         <?= in_array($lieu['id'], $lieuxAffectes, true) ? 'checked' : '' ?>
                         class="size-4 rounded border-slate-300 text-marine-600 focus:ring-marine-500">
                  <?= e($lieu['nom']) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="mt-1 text-xs text-slate-500">Aucun lieu coché : l'utilisateur ouvre le premier lieu actif.</p>
          </div>

          <div class="sm:col-span-2"><?= caseACocher('actif', (bool) $v['actif'], 'Compte actif') ?></div>
          <div class="sm:col-span-2 flex justify-end"><?= bouton($utilisateur ? 'Enregistrer' : 'Créer le compte') ?></div>
        </form>
      <?= carteFin() ?>
    </div>
<?php });
