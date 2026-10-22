<?php
/**
 * Tableau de bord. Port de `src/app/(app)/tableau-de-bord/page.tsx`.
 *
 * Les chiffres viennent de `syntheseTableauDeBord()` (lib/synthese), partagée
 * avec le rapport imprimé.
 */

// Fermé au caissier comme au commercial : la garde double le filtrage du menu,
// l'URL restant accessible à la main.
$session = exigerRole(...ROLES_SYNTHESE);

// Par défaut les trente derniers jours.
[$du, $au] = periodeDemandee((new DateTime())->modify('-29 days')->format('Y-m-d'));

extract(syntheseTableauDeBord($session['lieu_id'], $du, $au));

page('Tableau de bord', function () use ($valeurs, $points, $ventes, $derniersDocs, $stockCritique, $effetsProches, $du, $au) {
    $filtre = '<form method="get" class="flex flex-wrap items-end gap-2">'
        . '<label class="text-xs text-slate-500">Du<input type="date" name="du" value="' . e(substr($du, 0, 10)) . '" class="' . CLASSES_SAISIE . ' mt-1"></label>'
        . '<label class="text-xs text-slate-500">Au<input type="date" name="au" value="' . e(substr($au, 0, 10)) . '" class="' . CLASSES_SAISIE . ' mt-1"></label>'
        . bouton('Filtrer', ['variante' => 'secondaire'])
        . '</form>';

    $actions = $filtre
        . boutonLien('/impression/rapport/tableau-de-bord?' . requetePeriode($du, $au), 'Imprimer le rapport',
            ['variante' => 'secondaire', 'icone' => 'imprimante']);

    echo enTetePage('Bienvenue sur OUTECH', 'Période ' . libellePeriode($du, $au), $actions);
    ?>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?php foreach (TUILES_TABLEAU_DE_BORD as $tuile): ?>
        <div class="flex items-center gap-4 rounded-xl <?= e($tuile['couleur']) ?> p-4 text-white shadow-sm">
          <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-white/20"><?= icone($tuile['icone'], 'size-5') ?></span>
          <div class="min-w-0">
            <p class="truncate text-sm/5 text-white/85"><?= e($tuile['libelle']) ?></p>
            <p class="truncate text-lg font-semibold"><?= e(montant($valeurs[$tuile['cle']], devise())) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-5">
      <?= carteDebut('Ventes de la période', ['sous_titre' => $ventes['n'] . ' facture' . ($ventes['n'] > 1 ? 's' : '')]) ?>
      <?= courbeVentes($points) ?>
      <?= carteFin() ?>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
      <?= carteDebut('Documents récents', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Date', 'Numéro', 'Tiers', ['libelle' => 'Total', 'classe' => 'text-right'], 'Paiement']) ?>
        <?php if (!$derniersDocs) echo ligneVide(5); ?>
        <?php foreach ($derniersDocs as $doc): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($doc['date'])) ?></td>
            <td class="<?= CLASSES_TD ?>">
              <a href="<?= e(u(urlDocument($doc['type'], $doc['id']))) ?>" class="font-medium text-marine-600 hover:underline"><?= e($doc['numero']) ?></a>
            </td>
            <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($doc['tiers_nom'] ?? 'Client de passage') ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($doc['total_ttc'], devise())) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_PAIEMENT, $doc['statut_paiement']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <div class="space-y-5">
        <?= carteDebut('Alertes de stock', ['sous_titre' => "Articles au niveau d'alerte ou en dessous", 'sans_padding' => true]) ?>
          <?= tableauDebut(['Article', ['libelle' => 'Stock', 'classe' => 'text-right'], ['libelle' => 'Alerte', 'classe' => 'text-right']]) ?>
          <?php if (!$stockCritique) echo ligneVide(3, 'Aucune alerte de stock'); ?>
          <?php foreach ($stockCritique as $s): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> max-w-56 truncate">
                <a href="<?= e(u('/stock/articles/' . $s['article_id'])) ?>" class="text-marine-600 hover:underline"><?= e($s['nom']) ?></a>
              </td>
              <td class="<?= CLASSES_TD ?> text-right font-medium text-rose-600"><?= e(nombre($s['quantite'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right text-slate-500"><?= e(nombre($s['stock_alerte'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>

        <?= carteDebut('Échéances chèques / effets', ['sous_titre' => "En attente d'encaissement", 'sans_padding' => true]) ?>
          <?= tableauDebut(['Échéance', 'Tiers', ['libelle' => 'Montant', 'classe' => 'text-right']]) ?>
          <?php if (!$effetsProches) echo ligneVide(3, 'Aucun effet en attente'); ?>
          <?php foreach ($effetsProches as $effet): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> whitespace-nowrap">
                <span class="inline-flex items-center gap-1.5"><span class="text-slate-400"><?= icone('horloge', 'size-3.5') ?></span><?= e(dateFr($effet['date_echeance'])) ?></span>
              </td>
              <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($effet['tiers_nom'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($effet['montant'], devise())) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
