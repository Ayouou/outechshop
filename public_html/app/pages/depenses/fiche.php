<?php
/**
 * `/depenses/{id}`.
 *
 * Une dépense ordinaire s'ouvre dans son formulaire de modification. Une
 * consommation, non : ses montants viennent des lignes et du stock, les
 * retoucher à la main désaccorderait les deux. Elle se lit ici, et se corrige
 * en la reprenant puis en la redéclarant.
 */

$depenseCourante = un('SELECT * FROM depense WHERE id = ?', [$params['id'] ?? '']);

if (!$depenseCourante || ($depenseCourante['origine'] ?? 'SAISIE') !== 'CONSOMMATION') {
    require __DIR__ . '/formulaire.php';
    return;
}

$session = exigerSession();

if (estPost() && champ('_action') === 'supprimer') {
    supprimerConsommationDepense($depenseCourante['id']);
    rediriger('/depenses/consommation', 'Déclaration reprise, la matière revient au stock.');
}

$lignes = lignesConsommation($depenseCourante['id']);
$auteur = $depenseCourante['user_id']
    ? valeur('SELECT nom FROM utilisateur WHERE id = ?', [$depenseCourante['user_id']])
    : null;
$categorie = $depenseCourante['categorie_id']
    ? valeur('SELECT nom FROM categorie_depense WHERE id = ?', [$depenseCourante['categorie_id']])
    : null;

page('Consommation ' . $depenseCourante['numero'], function () use ($depenseCourante, $lignes, $auteur, $categorie) {
    echo enTetePage(
        $depenseCourante['designation'],
        $depenseCourante['numero'] . ' · ' . dateFr($depenseCourante['date']),
        boutonLien('/depenses/consommation', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g'])
        . actionBouton('supprimer', ['id' => $depenseCourante['id']], 'Reprendre', [
            'variante' => 'danger', 'icone' => 'poubelle',
            'confirmation' => 'Reprendre cette consommation ? La matière revient au stock.',
        ]),
    );
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?= carteDebut('Consommables sortis', ['sans_padding' => true]) ?>
          <?php
          echo tableauDebut(['Consommable',
              ['libelle' => 'Quantité', 'classe' => 'text-right'],
              ['libelle' => 'Prix d’achat', 'classe' => 'text-right'],
              ['libelle' => 'Valeur', 'classe' => 'text-right']]);

          if (!$lignes) echo ligneVide(4, 'Aucune ligne');

          foreach ($lignes as $ligne) { ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?php if ($ligne['article_id']): ?>
                  <a href="<?= e(u('/stock/articles/' . $ligne['article_id'])) ?>"
                     class="font-medium text-marine-600 hover:underline"><?= e($ligne['designation']) ?></a>
                <?php else: ?>
                  <span class="font-medium text-slate-800"><?= e($ligne['designation']) ?></span>
                  <span class="ml-2 text-xs text-slate-400">article supprimé depuis</span>
                <?php endif; ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap">
                <?= e(nombre($ligne['quantite']) . ' ' . ($ligne['unite'] ?? '')) ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap text-slate-500">
                <?= e(montant($ligne['prix_achat'], devise())) ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap">
                <?= e(montant($ligne['total'], devise())) ?>
              </td>
            </tr>
          <?php }

          echo tableauFin();
          ?>
          <div class="flex justify-between border-t border-slate-200 px-4 py-3">
            <span class="text-sm font-semibold text-slate-800">Total consommé</span>
            <span class="text-base font-bold text-marine-700"><?= e(montant($depenseCourante['montant_ttc'], devise())) ?></span>
          </div>
        <?= carteFin() ?>
      </div>

      <div>
        <?= carteDebut('Détails') ?>
          <dl class="space-y-1">
            <?= ligneInfo('Date', dateFr($depenseCourante['date'])) ?>
            <?= ligneInfo('Catégorie', $categorie ?? '—') ?>
            <?= ligneInfo('Déclarée par', $auteur ?? '—') ?>
            <?= ligneInfo('Note', $depenseCourante['note'] ?: '—') ?>
          </dl>
          <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-600">
            Les prix ci-dessus sont ceux du jour de la déclaration : ils ne bougent plus,
            même si la fiche article change. Pour corriger une erreur, reprenez la
            déclaration — le stock est rendu — puis redéclarez-la.
          </p>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
