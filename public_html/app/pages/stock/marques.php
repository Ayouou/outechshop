<?php
/** Marques : liste et création. */

exigerSession();

$erreur = null;

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        supprimer('marque', (string) champ('id', ''));
        rediriger('/stock/marques', 'Marque supprimée.');
    }

    $nom = (string) champ('nom', '');
    if (mb_strlen($nom) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            inserer('marque', ['nom' => $nom]);
            rediriger('/stock/marques', 'Marque créée.');
        } catch (PDOException $e) {
            $erreur = 'Cette marque existe déjà.';
        }
    }
}

$marques = tous(
    'SELECT m.*, (SELECT COUNT(*) FROM article a WHERE a.marque_id = m.id) AS articles
       FROM marque m ORDER BY m.nom',
);

page('Marques', function () use ($marques, $erreur) {
    echo enTetePage('Marques', 'Fabricants et fournisseurs de vos articles');
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut('Nouvelle marque') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
            <?= champBloc('Nom', saisie('nom', '', ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= bouton('Créer', ['classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Toutes les marques', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Action', 'Nom', ['libelle' => 'Articles', 'classe' => 'text-right']]) ?>
          <?php if (!$marques) echo ligneVide(3); ?>
          <?php foreach ($marques as $marque): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?= menuActions([
                    lienMenu('/stock/marques/' . $marque['id'], 'Modifier', 'crayon'),
                    actionMenu('supprimer', ['id' => $marque['id']], 'Effacer', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => "Supprimer « {$marque['nom']} » ?",
                    ]),
                ]) ?>
              </td>
              <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($marque['nom']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $marque['articles'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
