<?php
/** Création / modification d'un tiers. Port de `FormulaireTiers.tsx` + `actions.ts`. */

exigerSession();

$id = $params['id'] ?? null;
$tiers = null;

if ($id) {
    $tiers = un('SELECT * FROM tiers WHERE id = ?', [$id]);
    if (!$tiers) introuvable('Tiers introuvable');
}

$groupes = tous('SELECT id, nom FROM groupe_client ORDER BY nom');
$groupesPrix = tous('SELECT id, nom FROM groupe_prix WHERE actif = 1 ORDER BY nom');

$erreur = null;
$v = $tiers ?? [
    'type'                => param('type', 'CLIENT'),
    'nom'                 => '',
    'nom_entreprise'      => '',
    'email'               => '',
    'telephone'           => '',
    'mobile'              => '',
    'ice'                 => '',
    'rc'                  => '',
    'adresse'             => '',
    'ville'               => '',
    'conditions_paiement' => '',
    'solde_ouverture'     => '0',
    'plafond_credit'      => '0',
    'groupe_id'           => '',
    'groupe_prix_id'      => '',
    'notes'               => '',
    'actif'               => 1,
];

if (estPost()) {
    $donnees = [
        'type'                => in_array(champ('type'), ['CLIENT', 'FOURNISSEUR', 'LES_DEUX'], true) ? champ('type') : 'CLIENT',
        'nom'                 => (string) champ('nom', ''),
        'nom_entreprise'      => ouNull(champ('nom_entreprise')),
        'email'               => ouNull(champ('email')),
        'telephone'           => ouNull(champ('telephone')),
        'mobile'              => ouNull(champ('mobile')),
        'ice'                 => ouNull(champ('ice')),
        'rc'                  => ouNull(champ('rc')),
        'adresse'             => ouNull(champ('adresse')),
        'ville'               => ouNull(champ('ville')),
        'conditions_paiement' => ouNull(champ('conditions_paiement')),
        'solde_ouverture'     => champNombre('solde_ouverture'),
        'plafond_credit'      => champNombre('plafond_credit'),
        'groupe_id'           => ouNull(champ('groupe_id')),
        'groupe_prix_id'      => ouNull(champ('groupe_prix_id')),
        'notes'               => ouNull(champ('notes')),
        'actif'               => champBool('actif') ? 1 : 0,
    ];

    $v = array_merge($v, $donnees);

    if (mb_strlen($donnees['nom']) < 2) {
        $erreur = 'Le nom est obligatoire';
    } elseif ($donnees['email'] && !filter_var($donnees['email'], FILTER_VALIDATE_EMAIL)) {
        $erreur = 'E-mail invalide';
    } else {
        if ($tiers) {
            modifier('tiers', $tiers['id'], $donnees);
            rediriger('/tiers/' . $tiers['id'], 'Tiers enregistré.');
        }

        // Code lisible : CL2026001 / FR2026001, dérivé du compteur annuel.
        $prefixe = $donnees['type'] === 'FOURNISSEUR' ? 'FR' : 'CL';
        $donnees['code'] = str_replace('/', '', numeroSuivant($prefixe));
        $nouveau = inserer('tiers', $donnees);
        rediriger('/tiers/' . $nouveau, 'Tiers créé.');
    }
}

page($tiers ? 'Modifier le tiers' : 'Nouveau tiers', function () use ($tiers, $v, $erreur, $groupes, $groupesPrix) {
    echo enTetePage(
        $tiers ? 'Modifier « ' . $tiers['nom'] . ' »' : 'Nouveau tiers',
        'Client, fournisseur ou les deux',
        boutonLien($tiers ? '/tiers/' . $tiers['id'] : '/tiers/clients', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    $optionsGroupes = ['' => '— Aucun —'];
    foreach ($groupes as $g) $optionsGroupes[$g['id']] = $g['nom'];

    $optionsPrix = ['' => '— Prix de base —'];
    foreach ($groupesPrix as $g) $optionsPrix[$g['id']] = $g['nom'];
    ?>

    <form method="post" class="space-y-5">
      <?= champCsrf() ?>
      <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

      <?= carteDebut('Informations générales') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?= champBloc('Type', listeDeroulante('type', TYPES_TIERS, $v['type']), ['obligatoire' => true]) ?>
          <?= champBloc('Nom', saisie('nom', $v['nom'], ['attrs' => ['required' => true, 'placeholder' => 'Nom du contact']]), ['obligatoire' => true, 'classe' => 'sm:col-span-2']) ?>
          <?= champBloc("Nom de l'entreprise", saisie('nom_entreprise', $v['nom_entreprise'])) ?>
          <?= champBloc('Téléphone', saisie('telephone', $v['telephone'])) ?>
          <?= champBloc('Mobile', saisie('mobile', $v['mobile'])) ?>
          <?= champBloc('E-mail', saisie('email', $v['email'], ['type' => 'email'])) ?>
          <?= champBloc('Ville', saisie('ville', $v['ville'])) ?>
          <?= champBloc('Groupe de clients', listeDeroulante('groupe_id', $optionsGroupes, $v['groupe_id'])) ?>
          <?= champBloc('Groupe de prix de vente', listeDeroulante('groupe_prix_id', $optionsPrix, $v['groupe_prix_id']), ['aide' => 'Applique automatiquement sa grille tarifaire']) ?>
          <?= champBloc('Adresse', saisie('adresse', $v['adresse']), ['classe' => 'sm:col-span-2 lg:col-span-3']) ?>
        </div>
      <?= carteFin() ?>

      <?= carteDebut('Informations légales et financières') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?= champBloc('ICE', saisie('ice', $v['ice'])) ?>
          <?= champBloc('RC', saisie('rc', $v['rc'])) ?>
          <?= champBloc('Conditions de paiement', saisie('conditions_paiement', $v['conditions_paiement'], ['attrs' => ['placeholder' => '30 jours fin de mois']])) ?>
          <?= champBloc("Solde d'ouverture (" . devise() . ')', saisie('solde_ouverture', $v['solde_ouverture'], ['type' => 'number', 'attrs' => ['step' => '0.01']])) ?>
          <?= champBloc('Plafond de crédit (' . devise() . ')', saisie('plafond_credit', $v['plafond_credit'], ['type' => 'number', 'attrs' => ['step' => '0.01']])) ?>
          <?= champBloc('Notes', zoneTexte('notes', $v['notes']), ['classe' => 'sm:col-span-2 lg:col-span-3']) ?>
        </div>
        <div class="mt-4"><?= caseACocher('actif', (bool) $v['actif'], 'Tiers actif') ?></div>
      <?= carteFin() ?>

      <div class="flex justify-end gap-2">
        <?= bouton($tiers ? 'Enregistrer' : 'Créer le tiers') ?>
      </div>
    </form>
<?php });
