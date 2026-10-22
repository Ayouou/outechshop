<?php
/** Machines de l'atelier : liste, création et modification. */

exigerRole('ADMIN', 'MANAGER');

$erreur = null;

// Le formulaire sert aussi à modifier : `?modifier=…` le remplit avec la machine.
$modifiee = param('modifier') ? un('SELECT * FROM machine WHERE id = ?', [param('modifier')]) : null;

$v = $modifiee ?? [
    'id' => '', 'code' => '', 'nom' => '', 'type' => 'IMPRESSION',
    'laize' => '0', 'actif' => 1, 'note' => '',
];

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        supprimer('machine', (string) champ('id', ''));
        rediriger('/imprimerie/machines', 'Machine supprimée.');
    }

    $id = ouNull(champ('id'));
    $nom = (string) champ('nom', '');
    $code = (string) champ('code', '');

    $donnees = [
        'code'         => $code,
        'nom'          => $nom,
        'type'         => isset(TYPES_MACHINE[(string) champ('type')]) ? (string) champ('type') : 'IMPRESSION',
        'laize'        => max(0, champNombre('laize')),
        'actif'        => champBool('actif') ? 1 : 0,
        'note'         => ouNull(champ('note')),
    ];
    $v = array_merge($v, $donnees, ['id' => $id]);

    if (mb_strlen($nom) < 2 || mb_strlen($code) < 1) {
        $erreur = 'Le code et le nom sont obligatoires';
    } else {
        try {
            if ($id) {
                modifier('machine', $id, $donnees);
                rediriger('/imprimerie/machines', 'Machine enregistrée.');
            }
            inserer('machine', $donnees);
            rediriger('/imprimerie/machines', 'Machine créée.');
        } catch (PDOException $e) {
            $erreur = 'Ce code de machine est déjà pris.';
        }
    }
}

$machines = tous(
    'SELECT m.*,
            (SELECT COUNT(*) FROM travail t WHERE t.machine_id = m.id) AS travaux,
            (SELECT COALESCE(SUM(c.quantite + c.chute), 0) FROM consommation c WHERE c.machine_id = m.id) AS matiere
       FROM machine m ORDER BY m.actif DESC, m.nom',
);

page('Machines', function () use ($machines, $v, $erreur, $modifiee) {
    echo enTetePage('Machines de l\'atelier',
        'Traceur, presse DTF, plotter de découpe… la laize sert à contrôler les formats');
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut($modifiee ? 'Modifier la machine' : 'Nouvelle machine') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <input type="hidden" name="id" value="<?= e($v['id'] ?? '') ?>">
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
            <?= champBloc('Code', saisie('code', $v['code'], ['attrs' => ['required' => true, 'placeholder' => 'TRACEUR-1']]), ['obligatoire' => true]) ?>
            <?= champBloc('Nom', saisie('nom', $v['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= champBloc('Type', listeDeroulante('type', TYPES_MACHINE, $v['type'])) ?>
            <?= champBloc('Laize (m)', saisie('laize', num($v['laize']), ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]]), ['aide' => 'Largeur utile du support ; 0 si sans objet']) ?>
            <?= champBloc('Note', zoneTexte('note', $v['note'], ['lignes' => 2])) ?>
            <?= caseACocher('actif', (bool) $v['actif'], 'Machine en service') ?>
            <div class="flex gap-2">
              <?= bouton($modifiee ? 'Enregistrer' : 'Créer', ['classe' => 'flex-1']) ?>
              <?php if ($modifiee) echo boutonLien('/imprimerie/machines', 'Annuler', ['variante' => 'secondaire']); ?>
            </div>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Toutes les machines', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Action', 'Code', 'Nom', 'Type',
              ['libelle' => 'Laize', 'classe' => 'text-right'],
              ['libelle' => 'Travaux', 'classe' => 'text-right'],
              ['libelle' => 'Matière sortie', 'classe' => 'text-right'], 'État']) ?>
          <?php if (!$machines) echo ligneVide(8, 'Aucune machine enregistrée'); ?>
          <?php foreach ($machines as $machine): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?= menuActions([
                    lienMenu('/imprimerie/machines?modifier=' . $machine['id'], 'Modifier', 'crayon'),
                    actionMenu('supprimer', ['id' => $machine['id']], 'Effacer', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => "Supprimer « {$machine['nom']} » ? Les travaux et consommations la perdront comme référence.",
                    ]),
                ]) ?>
              </td>
              <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($machine['code']) ?></td>
              <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($machine['nom']) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e(TYPES_MACHINE[$machine['type']] ?? $machine['type']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= num($machine['laize']) > 0 ? e(nombre($machine['laize']) . ' m') : '—' ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $machine['travaux'] ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($machine['matiere'])) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= $machine['actif'] ? badge('En service', 'vert') : badge('Arrêtée', 'gris') ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
