<?php
/** Catégories de dépenses. */

exigerSession();

$erreur = null;

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        supprimer('categorie_depense', (string) champ('id', ''));
        rediriger('/depenses/categories', 'Catégorie supprimée.');
    }

    $nom = (string) champ('nom', '');
    $modifier = ouNull(champ('id'));

    if (mb_strlen($nom) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            if ($modifier) modifier('categorie_depense', $modifier, ['nom' => $nom]);
            else inserer('categorie_depense', ['nom' => $nom]);
            rediriger('/depenses/categories', 'Catégorie enregistrée.');
        } catch (PDOException $e) {
            $erreur = 'Cette catégorie existe déjà.';
        }
    }
}

// Les deux natures sont comptées à part : additionner l'achat du papier et sa
// consommation dans une même colonne compterait deux fois la même rame.
$categories = tous(
    "SELECT c.*,
            (SELECT COUNT(*) FROM depense d
              WHERE d.categorie_id = c.id AND d.origine <> 'CONSOMMATION') AS depenses,
            (SELECT COALESCE(SUM(d.montant_ttc), 0) FROM depense d
              WHERE d.categorie_id = c.id AND d.origine <> 'CONSOMMATION') AS total,
            (SELECT COALESCE(SUM(d.montant_ttc), 0) FROM depense d
              WHERE d.categorie_id = c.id AND d.origine  = 'CONSOMMATION') AS consomme
       FROM categorie_depense c ORDER BY c.nom",
);

page('Catégories de dépenses', function () use ($categories, $erreur) {
    echo enTetePage('Catégories de dépenses', 'Loyer, salaires, fournitures…',
        boutonLien('/depenses', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']));
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut('Nouvelle catégorie') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
            <?= champBloc('Nom', saisie('nom', '', ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= bouton('Créer', ['classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Toutes les catégories', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Nom', ['libelle' => 'Dépenses', 'classe' => 'text-right'],
              ['libelle' => 'Total TTC', 'classe' => 'text-right'],
              ['libelle' => 'Stock consommé', 'classe' => 'text-right'], '']) ?>
          <?php if (!$categories) echo ligneVide(5); ?>
          <?php foreach ($categories as $categorie): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <form method="post" class="flex items-center gap-2">
                  <?= champCsrf() ?>
                  <input type="hidden" name="id" value="<?= e($categorie['id']) ?>">
                  <input name="nom" value="<?= e($categorie['nom']) ?>" class="<?= CLASSES_SAISIE ?> max-w-xs">
                  <?= bouton('Renommer', ['variante' => 'secondaire']) ?>
                </form>
              </td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $categorie['depenses'] ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($categorie['total'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= num($categorie['consomme']) > 0 ? 'text-amber-700' : 'text-slate-400' ?>">
                <?= e(montant($categorie['consomme'], devise())) ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right">
                <?= actionBouton('supprimer', ['id' => $categorie['id']], 'Effacer', [
                    'variante' => 'fantome', 'classe' => 'text-rose-600',
                    'confirmation' => "Supprimer « {$categorie['nom']} » ?",
                ]) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
