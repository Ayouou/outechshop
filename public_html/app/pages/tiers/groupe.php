<?php
/** Modification d'un groupe de clients. */

exigerSession();

$groupe = un('SELECT * FROM groupe_client WHERE id = ?', [$params['id']]);
if (!$groupe) introuvable('Groupe introuvable');

$erreur = null;

if (estPost()) {
    $donnees = [
        'nom'             => (string) champ('nom', ''),
        'remise_pourcent' => min(100, max(0, champNombre('remise_pourcent'))),
        'description'     => ouNull(champ('description')),
    ];
    $groupe = array_merge($groupe, $donnees);

    if (mb_strlen($donnees['nom']) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            modifier('groupe_client', $params['id'], $donnees);
            rediriger('/tiers/groupes', 'Groupe enregistré.');
        } catch (PDOException $e) {
            $erreur = 'Un groupe portant ce nom existe déjà.';
        }
    }
}

page('Modifier le groupe', function () use ($groupe, $erreur) {
    echo enTetePage('Modifier « ' . $groupe['nom'] . ' »', 'Groupe de clients',
        boutonLien('/tiers/groupes', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']));
    ?>
    <div class="max-w-xl">
      <?= carteDebut('Informations') ?>
        <form method="post" class="space-y-4">
          <?= champCsrf() ?>
          <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
          <?= champBloc('Nom', saisie('nom', $groupe['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
          <?= champBloc('Remise (%)', saisie('remise_pourcent', $groupe['remise_pourcent'], ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0, 'max' => 100]])) ?>
          <?= champBloc('Description', zoneTexte('description', $groupe['description'], ['lignes' => 3])) ?>
          <div class="flex justify-end"><?= bouton('Enregistrer') ?></div>
        </form>
      <?= carteFin() ?>
    </div>
<?php });
