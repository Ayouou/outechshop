<?php
/** Catégories d'articles : arborescence simple (parent facultatif). */

exigerSession();

$erreur = null;

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        supprimer('categorie', (string) champ('id', ''));
        rediriger('/stock/categories', 'Catégorie supprimée.');
    }

    $nom = (string) champ('nom', '');
    if (mb_strlen($nom) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            inserer('categorie', ['nom' => $nom, 'parent_id' => ouNull(champ('parent_id'))]);
            rediriger('/stock/categories', 'Catégorie créée.');
        } catch (PDOException $e) {
            $erreur = 'Cette catégorie existe déjà.';
        }
    }
}

$categories = tous(
    'SELECT c.*, p.nom AS parent_nom,
            (SELECT COUNT(*) FROM article a WHERE a.categorie_id = c.id) AS articles
       FROM categorie c LEFT JOIN categorie p ON p.id = c.parent_id
      ORDER BY c.nom',
);

page('Catégories', function () use ($categories, $erreur) {
    echo enTetePage('Catégories', 'Classement des articles');

    $optionsParent = ['' => '— Aucune —'];
    foreach ($categories as $c) $optionsParent[$c['id']] = $c['nom'];
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut('Nouvelle catégorie') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
            <?= champBloc('Nom', saisie('nom', '', ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= champBloc('Catégorie parente', listeDeroulante('parent_id', $optionsParent, '')) ?>
            <?= bouton('Créer', ['classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Toutes les catégories', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Action', 'Nom', 'Parente', ['libelle' => 'Articles', 'classe' => 'text-right']]) ?>
          <?php if (!$categories) echo ligneVide(4); ?>
          <?php foreach ($categories as $categorie): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?= menuActions([
                    lienMenu('/stock/categories/' . $categorie['id'], 'Modifier', 'crayon'),
                    actionMenu('supprimer', ['id' => $categorie['id']], 'Effacer', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => "Supprimer « {$categorie['nom']} » ?",
                    ]),
                ]) ?>
              </td>
              <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($categorie['nom']) ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($categorie['parent_nom'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $categorie['articles'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
