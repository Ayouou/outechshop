<?php
/** Groupes de clients : liste et création. */

exigerSession();

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'supprimer') {
        supprimer('groupe_client', (string) champ('id', ''));
        rediriger('/tiers/groupes', 'Groupe supprimé.');
    }

    $donnees = [
        'nom'             => (string) champ('nom', ''),
        'remise_pourcent' => min(100, max(0, champNombre('remise_pourcent'))),
        'description'     => ouNull(champ('description')),
    ];

    if (mb_strlen($donnees['nom']) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            inserer('groupe_client', $donnees);
            rediriger('/tiers/groupes', 'Groupe créé.');
        } catch (PDOException $e) {
            $erreur = 'Un groupe portant ce nom existe déjà.';
        }
    }
}

$groupes = tous(
    'SELECT g.*, (SELECT COUNT(*) FROM tiers t WHERE t.groupe_id = g.id) AS clients
       FROM groupe_client g ORDER BY g.nom',
);

page('Groupes de clients', function () use ($groupes, $erreur) {
    echo enTetePage('Groupes de clients', 'Tarification et remises par catégorie de clientèle');
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut('Nouveau groupe') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
            <?= champBloc('Nom', saisie('nom', champ('nom', ''), ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= champBloc('Remise (%)', saisie('remise_pourcent', champ('remise_pourcent', '0'), ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0, 'max' => 100]])) ?>
            <?= champBloc('Description', zoneTexte('description', champ('description', ''), ['lignes' => 2])) ?>
            <?= bouton('Créer le groupe', ['classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Tous les groupes', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Action', 'Nom', ['libelle' => 'Remise', 'classe' => 'text-right'], ['libelle' => 'Clients', 'classe' => 'text-right'], 'Description']) ?>
          <?php if (!$groupes) echo ligneVide(5); ?>
          <?php foreach ($groupes as $groupe): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?= menuActions([
                    lienMenu('/tiers/groupes/' . $groupe['id'], 'Modifier', 'crayon'),
                    actionMenu('supprimer', ['id' => $groupe['id']], 'Effacer', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => "Supprimer le groupe « {$groupe['nom']} » ?",
                    ]),
                ]) ?>
              </td>
              <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($groupe['nom']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(pourcent($groupe['remise_pourcent'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $groupe['clients'] ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($groupe['description'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
