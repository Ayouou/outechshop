<?php
/**
 * Rapports : CA, marge brute, résultat, meilleurs articles et clients,
 * stock valorisé. Port de `src/app/(app)/rapports/page.tsx`.
 *
 * Les chiffres viennent de `syntheseActivite()` (lib/synthese), partagée avec
 * le rapport imprimé.
 */

// Même restriction que le tableau de bord : les synthèses ne sont pas ouvertes
// au caissier.
$session = exigerRole(...ROLES_SYNTHESE);

// Par défaut le mois en cours, jusqu'à aujourd'hui.
[$du, $au] = periodeDemandee(date('Y-m-01'));

extract(syntheseActivite($session['lieu_id'], $du, $au));

page('Rapports', function () use (
    $du, $au, $caHT, $caTTC, $encaisse, $margeBrute, $coutVentes, $resultat,
    $totalAchats, $totalDepenses, $valeurStock, $valeurStockVente,
    $points, $topArticles, $topClients, $factures, $achats, $depenses
) {
    $filtre = '<form method="get" class="flex flex-wrap items-end gap-2">'
        . '<label class="text-xs text-slate-500">Du<input type="date" name="du" value="' . e(substr($du, 0, 10)) . '" class="' . CLASSES_SAISIE . ' mt-1"></label>'
        . '<label class="text-xs text-slate-500">Au<input type="date" name="au" value="' . e(substr($au, 0, 10)) . '" class="' . CLASSES_SAISIE . ' mt-1"></label>'
        . bouton('Filtrer', ['variante' => 'secondaire']) . '</form>';

    $actions = $filtre
        . boutonLien('/impression/rapport/activite?' . requetePeriode($du, $au), 'Imprimer le rapport',
            ['variante' => 'secondaire', 'icone' => 'imprimante']);

    echo enTetePage('Rapports', 'Période ' . libellePeriode($du, $au), $actions);
    ?>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?= tuile("Chiffre d'affaires HT", montant($caHT, devise()), ['detail' => count($factures) . ' vente(s) · ' . montant($caTTC, devise()) . ' TTC', 'icone' => 'recu']) ?>
      <?= tuile('Marge brute', montant($margeBrute, devise()), ['detail' => 'Coût des ventes : ' . montant($coutVentes, devise()), 'couleur' => 'text-emerald-600', 'icone' => 'tendance']) ?>
      <?= tuile('Dépenses', montant($totalDepenses, devise()), ['detail' => (int) $depenses['n'] . ' dépense(s)', 'icone' => 'portefeuille']) ?>
      <?= tuile('Résultat', montant($resultat, devise()), ['detail' => 'Marge brute − dépenses',
          'couleur' => $resultat >= 0 ? 'text-emerald-600' : 'text-rose-600', 'icone' => $resultat >= 0 ? 'tendance' : 'baisse']) ?>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?= tuile('Encaissé sur la période', montant($encaisse, devise()), ['detail' => 'Reste : ' . montant($caTTC - $encaisse, devise())]) ?>
      <?= tuile('Achats', montant($totalAchats, devise()), ['detail' => (int) $achats['n'] . ' achat(s)']) ?>
      <?= tuile("Stock au prix d'achat", montant($valeurStock, devise()), ['detail' => 'Valeur immobilisée']) ?>
      <?= tuile('Stock au prix de vente', montant($valeurStockVente, devise()), ['detail' => 'Potentiel de vente']) ?>
    </div>

    <div class="mt-5">
      <?= carteDebut("Chiffre d'affaires jour par jour") ?>
      <?= courbeVentes($points) ?>
      <?= carteFin() ?>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
      <?= carteDebut('Meilleurs articles', ['sous_titre' => 'Par chiffre d\'affaires HT', 'sans_padding' => true]) ?>
        <?= tableauDebut(['Article', ['libelle' => 'Quantité', 'classe' => 'text-right'],
            ['libelle' => 'CA HT', 'classe' => 'text-right'], ['libelle' => 'Marge', 'classe' => 'text-right']]) ?>
        <?php if (!$topArticles) echo ligneVide(4, 'Aucune vente sur la période'); ?>
        <?php foreach ($topArticles as $articleId => $ligne): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> max-w-56 truncate">
              <a href="<?= e(u('/stock/articles/' . $articleId)) ?>" class="text-marine-600 hover:underline"><?= e($ligne['nom']) ?></a>
            </td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['quantite'])) ?></td>
            <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($ligne['ca'], devise())) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= $ligne['marge'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= e(montant($ligne['marge'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <?= carteDebut('Meilleurs clients', ['sous_titre' => 'Par chiffre d\'affaires TTC', 'sans_padding' => true]) ?>
        <?= tableauDebut(['Client', ['libelle' => 'Documents', 'classe' => 'text-right'],
            ['libelle' => 'CA TTC', 'classe' => 'text-right']]) ?>
        <?php if (!$topClients) echo ligneVide(3, 'Aucune vente sur la période'); ?>
        <?php foreach ($topClients as $cle => $ligne): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> max-w-56 truncate">
              <?php if ($cle !== 'passage'): ?>
                <a href="<?= e(u('/tiers/' . $cle)) ?>" class="text-marine-600 hover:underline"><?= e($ligne['nom']) ?></a>
              <?php else: ?>
                <?= e($ligne['nom']) ?>
              <?php endif; ?>
            </td>
            <td class="<?= CLASSES_TD ?> text-right"><?= (int) $ligne['documents'] ?></td>
            <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($ligne['ca'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
