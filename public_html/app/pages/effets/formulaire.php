<?php
/** Création d'un chèque ou d'un effet saisi à la main. */

exigerSession();

$erreur = null;
$v = [
    'type'          => param('type') === 'EFFET' ? 'EFFET' : 'CHEQUE',
    'sens'          => 'RECU',
    'numero'        => '',
    'banque'        => '',
    'montant'       => '',
    'date_emission' => dateInput('now'),
    'date_echeance' => dateInput('now'),
    'tiers_id'      => '',
    'note'          => '',
];

if (estPost()) {
    foreach (array_keys($v) as $cle) $v[$cle] = (string) champ($cle, $v[$cle]);
    $montant = champNombre('montant');

    if ($v['numero'] === '') $erreur = 'Le numéro est obligatoire';
    elseif ($montant <= 0) $erreur = 'Le montant doit être positif';
    elseif ($v['date_echeance'] === '') $erreur = "La date d'échéance est obligatoire";
    else {
        inserer('effet', [
            'type'          => $v['type'] === 'EFFET' ? 'EFFET' : 'CHEQUE',
            'sens'          => $v['sens'] === 'EMIS' ? 'EMIS' : 'RECU',
            'numero'        => $v['numero'],
            'banque'        => ouNull($v['banque']),
            'montant'       => $montant,
            'date_emission' => horodatage($v['date_emission']) ?? maintenant(),
            'date_echeance' => horodatage($v['date_echeance']),
            'tiers_id'      => ouNull($v['tiers_id']),
            'note'          => ouNull($v['note']),
        ]);
        rediriger($v['type'] === 'CHEQUE' ? '/effets/cheques' : '/effets/effets', 'Pièce enregistrée.');
    }
}

$tiers = tous('SELECT id, nom FROM tiers WHERE actif = 1 ORDER BY nom');

page('Nouvelle pièce', function () use ($v, $erreur, $tiers) {
    echo enTetePage('Nouveau chèque / effet', 'Pièce saisie manuellement',
        boutonLien('/effets', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']));

    $optionsTiers = ['' => '— Aucun —'];
    foreach ($tiers as $t) $optionsTiers[$t['id']] = $t['nom'];
    ?>
    <div class="max-w-2xl">
      <?= carteDebut('Informations') ?>
        <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <?= champCsrf() ?>
          <?php if ($erreur) echo '<div class="sm:col-span-2">' . alerte(e($erreur), 'erreur') . '</div>'; ?>
          <?= champBloc('Type', listeDeroulante('type', ['CHEQUE' => 'Chèque', 'EFFET' => 'Effet'], $v['type'])) ?>
          <?= champBloc('Sens', listeDeroulante('sens', ['RECU' => 'Reçu du client', 'EMIS' => 'Émis au fournisseur'], $v['sens'])) ?>
          <?= champBloc('Numéro', saisie('numero', $v['numero'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('Banque', saisie('banque', $v['banque'])) ?>
          <?= champBloc('Montant', saisie('montant', $v['montant'], ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => '0.01', 'required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('Tiers', listeDeroulante('tiers_id', $optionsTiers, $v['tiers_id'])) ?>
          <?= champBloc("Date d'émission", saisie('date_emission', $v['date_emission'], ['type' => 'date'])) ?>
          <?= champBloc("Date d'échéance", saisie('date_echeance', $v['date_echeance'], ['type' => 'date', 'attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('Note', zoneTexte('note', $v['note'], ['lignes' => 2]), ['classe' => 'sm:col-span-2']) ?>
          <div class="sm:col-span-2 flex justify-end"><?= bouton('Enregistrer') ?></div>
        </form>
      <?= carteFin() ?>
    </div>
<?php });
