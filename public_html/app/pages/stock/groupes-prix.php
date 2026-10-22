<?php
/** Groupes de prix de vente : liste et création. */

exigerSession();

$erreur = null;

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        $id = (string) champ('id', '');
        $rattaches = (int) valeur('SELECT COUNT(*) FROM tiers WHERE groupe_prix_id = ?', [$id]);
        if ($rattaches > 0) {
            // Groupe utilisé par des clients : on le désactive pour ne pas casser leur tarif.
            modifier('groupe_prix', $id, ['actif' => 0]);
            rediriger('/stock/groupes-prix', 'Groupe utilisé par des clients : il a été désactivé.', 'attention');
        }
        supprimer('groupe_prix', $id);
        rediriger('/stock/groupes-prix', 'Groupe supprimé.');
    }

    $nom = (string) champ('nom', '');
    if (mb_strlen($nom) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            inserer('groupe_prix', [
                'nom'         => $nom,
                'description' => ouNull(champ('description')),
                'actif'       => champBool('actif') ? 1 : 0,
            ]);
            rediriger('/stock/groupes-prix', 'Groupe de prix créé.');
        } catch (PDOException $e) {
            $erreur = 'Un groupe de prix portant ce nom existe déjà.';
        }
    }
}

$groupes = tous(
    'SELECT g.*,
            (SELECT COUNT(*) FROM prix_groupe p WHERE p.groupe_prix_id = g.id) AS tarifs,
            (SELECT COUNT(*) FROM tiers t WHERE t.groupe_prix_id = g.id) AS clients
       FROM groupe_prix g ORDER BY g.nom',
);

page('Groupes de prix de vente', function () use ($groupes, $erreur) {
    echo enTetePage('Groupes de prix de vente', 'Grilles tarifaires appliquées automatiquement selon le client');
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut('Nouveau groupe de prix') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
            <?= champBloc('Nom', saisie('nom', '', ['attrs' => ['required' => true, 'placeholder' => 'Grossistes']]), ['obligatoire' => true]) ?>
            <?= champBloc('Description', zoneTexte('description', '', ['lignes' => 2])) ?>
            <?= caseACocher('actif', true, 'Groupe actif') ?>
            <?= bouton('Créer', ['classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Tous les groupes', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Action', 'Nom', 'Description', ['libelle' => 'Tarifs définis', 'classe' => 'text-right'], ['libelle' => 'Clients', 'classe' => 'text-right'], 'Statut']) ?>
          <?php if (!$groupes) echo ligneVide(6, 'Aucun groupe de prix'); ?>
          <?php foreach ($groupes as $groupe): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?= menuActions([
                    lienMenu('/stock/groupes-prix/' . $groupe['id'], 'Grille tarifaire', 'etiquette'),
                    actionMenu('supprimer', ['id' => $groupe['id']], 'Effacer', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => "Supprimer « {$groupe['nom']} » ? S'il est utilisé par des clients, il sera seulement désactivé.",
                    ]),
                ]) ?>
              </td>
              <td class="<?= CLASSES_TD ?>">
                <a href="<?= e(u('/stock/groupes-prix/' . $groupe['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($groupe['nom']) ?></a>
              </td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($groupe['description'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $groupe['tarifs'] ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $groupe['clients'] ?></td>
              <td class="<?= CLASSES_TD ?>"><?= badge($groupe['actif'] ? 'Actif' : 'Inactif', $groupe['actif'] ? 'vert' : 'gris') ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
