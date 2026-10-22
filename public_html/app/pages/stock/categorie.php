<?php
/** Modification d'une catégorie. */

exigerSession();

$categorie = un('SELECT * FROM categorie WHERE id = ?', [$params['id']]);
if (!$categorie) introuvable('Catégorie introuvable');

$erreur = null;

if (estPost()) {
    $nom = (string) champ('nom', '');
    $parent = ouNull(champ('parent_id'));
    // Une catégorie ne peut pas être sa propre parente.
    if ($parent === $categorie['id']) $parent = null;

    if (mb_strlen($nom) < 2) {
        $erreur = 'Le nom est obligatoire';
        $categorie['nom'] = $nom;
    } else {
        try {
            modifier('categorie', $categorie['id'], ['nom' => $nom, 'parent_id' => $parent]);
            rediriger('/stock/categories', 'Catégorie enregistrée.');
        } catch (PDOException $e) {
            $erreur = 'Cette catégorie existe déjà.';
        }
    }
}

$autres = tous('SELECT id, nom FROM categorie WHERE id <> ? ORDER BY nom', [$categorie['id']]);

page('Modifier la catégorie', function () use ($categorie, $autres, $erreur) {
    echo enTetePage('Modifier « ' . $categorie['nom'] . ' »', 'Catégorie d\'articles',
        boutonLien('/stock/categories', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']));

    $optionsParent = ['' => '— Aucune —'];
    foreach ($autres as $c) $optionsParent[$c['id']] = $c['nom'];
    ?>
    <div class="max-w-xl">
      <?= carteDebut('Informations') ?>
        <form method="post" class="space-y-4">
          <?= champCsrf() ?>
          <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
          <?= champBloc('Nom', saisie('nom', $categorie['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('Catégorie parente', listeDeroulante('parent_id', $optionsParent, $categorie['parent_id'])) ?>
          <div class="flex justify-end"><?= bouton('Enregistrer') ?></div>
        </form>
      <?= carteFin() ?>
    </div>
<?php });
