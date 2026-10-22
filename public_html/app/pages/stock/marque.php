<?php
/** Modification d'une marque. */

exigerSession();

$marque = un('SELECT * FROM marque WHERE id = ?', [$params['id']]);
if (!$marque) introuvable('Marque introuvable');

$erreur = null;

if (estPost()) {
    $nom = (string) champ('nom', '');
    if (mb_strlen($nom) < 2) {
        $erreur = 'Le nom est obligatoire';
        $marque['nom'] = $nom;
    } else {
        try {
            modifier('marque', $marque['id'], ['nom' => $nom]);
            rediriger('/stock/marques', 'Marque enregistrée.');
        } catch (PDOException $e) {
            $erreur = 'Cette marque existe déjà.';
        }
    }
}

page('Modifier la marque', function () use ($marque, $erreur) {
    echo enTetePage('Modifier « ' . $marque['nom'] . ' »', 'Marque',
        boutonLien('/stock/marques', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']));
    ?>
    <div class="max-w-xl">
      <?= carteDebut('Informations') ?>
        <form method="post" class="space-y-4">
          <?= champCsrf() ?>
          <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
          <?= champBloc('Nom', saisie('nom', $marque['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <div class="flex justify-end"><?= bouton('Enregistrer') ?></div>
        </form>
      <?= carteFin() ?>
    </div>
<?php });
