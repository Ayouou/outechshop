<?php
/**
 * La caisse : recettes d'une journée — ou d'une période choisie. Écran
 * d'accueil du caissier. Port de `src/app/(app)/caisse/page.tsx`.
 *
 * Le détail du calcul vit dans `syntheseCaisse()` (lib/synthese), partagé avec
 * le rapport imprimé : l'écran et le papier ne peuvent pas diverger.
 */

$session = exigerSession();

// Par défaut la journée en cours ; `du`/`au` ouvrent la période, `jour` reste
// accepté pour les anciens liens.
[$du, $au] = periodeDemandee();

$synthese = syntheseCaisse($session['lieu_id'], $du, $au);
extract($synthese);

$unJour = periodeUnJour($du, $au);

page('La caisse', function () use (
    $du, $au, $unJour, $entrees, $sortiesAchats, $depenses, $ventes, $parMode,
    $totalEncaisse, $especesEntrees, $especesSorties, $totalVentes, $resteAEncaisser
) {
    $filtre = '<form method="get" class="flex flex-wrap items-end gap-2">'
        . '<label class="text-xs text-slate-500">Du<input type="date" name="du" value="' . e(substr($du, 0, 10)) . '" class="' . CLASSES_SAISIE . ' mt-1"></label>'
        . '<label class="text-xs text-slate-500">Au<input type="date" name="au" value="' . e(substr($au, 0, 10)) . '" class="' . CLASSES_SAISIE . ' mt-1"></label>'
        . bouton('Filtrer', ['variante' => 'secondaire'])
        . '</form>';

    $actions = $filtre
        . boutonLien('/impression/rapport/caisse?' . requetePeriode($du, $au), 'Imprimer le rapport',
            ['variante' => 'secondaire', 'icone' => 'imprimante'])
        . boutonLien('/pos', 'Point de vente', ['icone' => 'ecran']);

    echo enTetePage('La caisse',
        periodeAujourdhui($du, $au) ? "Recettes d'aujourd'hui" : 'Recettes ' . libellePeriode($du, $au),
        $actions);

    $tuiles = [
        ['libelle' => $unJour ? 'Encaissé dans la journée' : 'Encaissé sur la période',
         'valeur' => $totalEncaisse, 'couleur' => 'bg-emerald-500', 'icone' => 'caisse',
         'detail' => count($entrees) . ' règlement' . (count($entrees) > 1 ? 's' : '')],
        ['libelle' => 'Espèces en caisse', 'valeur' => $especesEntrees - $especesSorties, 'couleur' => 'bg-marine-600', 'icone' => 'billets',
         'detail' => montant($especesEntrees, devise()) . ' entrés · ' . montant($especesSorties, devise()) . ' sortis'],
        ['libelle' => $unJour ? 'Ventes du jour' : 'Ventes de la période',
         'valeur' => $totalVentes, 'couleur' => 'bg-blue-500', 'icone' => 'recu',
         'detail' => count($ventes) . ' vente' . (count($ventes) > 1 ? 's' : '')],
        ['libelle' => 'Reste à encaisser', 'valeur' => $resteAEncaisser, 'couleur' => 'bg-amber-500', 'icone' => 'alerte',
         'detail' => $unJour ? 'Sur les ventes du jour' : 'Sur les ventes de la période'],
    ];

    // Sur une seule journée l'heure suffit ; dès que la période s'ouvre, il faut
    // la date pour situer une ligne.
    $colonneQuand = $unJour ? 'Heure' : 'Date';
    $quand = fn($date) => $unJour ? heureFr($date) : dateHeureFr($date);
    ?>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($tuiles as $tuile): ?>
        <div class="flex items-center gap-4 rounded-xl <?= e($tuile['couleur']) ?> p-4 text-white shadow-sm">
          <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-white/20"><?= icone($tuile['icone'], 'size-5') ?></span>
          <div class="min-w-0">
            <p class="truncate text-sm/5 text-white/85"><?= e($tuile['libelle']) ?></p>
            <p class="truncate text-lg font-semibold"><?= e(montant($tuile['valeur'], devise())) ?></p>
            <p class="truncate text-xs text-white/75"><?= e($tuile['detail']) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-3">
      <?= carteDebut('Encaissements par mode', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Mode', ['libelle' => 'Nombre', 'classe' => 'text-right'], ['libelle' => 'Montant', 'classe' => 'text-right']]) ?>
        <?php if (!$parMode) echo ligneVide(3, 'Aucun encaissement'); ?>
        <?php foreach (MODES_PAIEMENT as $mode => $libelle): ?>
          <?php if (!isset($parMode[$mode])) continue; ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($libelle) ?></td>
            <td class="<?= CLASSES_TD ?> text-right text-slate-500"><?= (int) $parMode[$mode]['nombre'] ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($parMode[$mode]['total'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($parMode): ?>
          <tr class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-800">
            <td class="<?= CLASSES_TD ?>">Total</td>
            <td class="<?= CLASSES_TD ?> text-right"><?= count($entrees) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($totalEncaisse, devise())) ?></td>
          </tr>
        <?php endif; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <div class="xl:col-span-2">
        <?= carteDebut($unJour ? 'Ventes du jour' : 'Ventes de la période', ['sans_padding' => true]) ?>
          <?= tableauDebut([$colonneQuand, 'Numéro', 'Client',
              ['libelle' => 'Total', 'classe' => 'text-right'],
              ['libelle' => 'Payé', 'classe' => 'text-right'], 'Paiement']) ?>
          <?php if (!$ventes) echo ligneVide(6, $unJour ? 'Aucune vente ce jour' : 'Aucune vente sur la période'); ?>
          <?php foreach ($ventes as $vente): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($quand($vente['date'])) ?></td>
              <td class="<?= CLASSES_TD ?>">
                <a href="<?= e(u(urlDocument($vente['type'], $vente['id']))) ?>" class="font-medium text-marine-600 hover:underline"><?= e($vente['numero']) ?></a>
              </td>
              <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($vente['tiers_nom'] ?? 'Client de passage') ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($vente['total_ttc'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($vente['montant_paye'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_PAIEMENT, $vente['statut_paiement']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($ventes): ?>
            <tr class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-800">
              <td class="<?= CLASSES_TD ?>" colspan="3">Total</td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($totalVentes, devise())) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($totalVentes - $resteAEncaisser, devise())) ?></td>
              <td class="<?= CLASSES_TD ?>"></td>
            </tr>
          <?php endif; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
      <?= carteDebut('Détail des encaissements', ['sans_padding' => true]) ?>
        <?= tableauDebut([$colonneQuand, 'Document', 'Mode', 'Encaissé par', ['libelle' => 'Montant', 'classe' => 'text-right']]) ?>
        <?php if (!$entrees) echo ligneVide(5, 'Aucun encaissement'); ?>
        <?php foreach ($entrees as $reglement): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($quand($reglement['date'])) ?></td>
            <td class="<?= CLASSES_TD ?>">
              <a href="<?= e(u(urlDocument($reglement['document_type'], $reglement['document_id']))) ?>" class="font-medium text-marine-600 hover:underline"><?= e($reglement['numero']) ?></a>
              <span class="ml-2 text-xs text-slate-400"><?= e($reglement['tiers_nom'] ?? 'Client de passage') ?></span>
            </td>
            <td class="<?= CLASSES_TD ?> text-slate-500"><?= e(MODES_PAIEMENT[$reglement['mode']] ?? $reglement['mode']) ?></td>
            <td class="<?= CLASSES_TD ?> max-w-32 truncate text-slate-500"><?= e($reglement['user_nom'] ?? '—') ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($reglement['montant'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <?= carteDebut('Sorties de caisse', ['sous_titre' => 'Achats réglés et dépenses payées ' . ($unJour ? 'dans la journée' : 'sur la période'), 'sans_padding' => true]) ?>
        <?= tableauDebut([$colonneQuand, 'Objet', 'Mode', ['libelle' => 'Montant', 'classe' => 'text-right']]) ?>
        <?php if (!$sortiesAchats && !$depenses) echo ligneVide(4, 'Aucune sortie'); ?>
        <?php foreach ($sortiesAchats as $reglement): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($quand($reglement['date'])) ?></td>
            <td class="<?= CLASSES_TD ?>">
              <a href="<?= e(u(urlDocument($reglement['document_type'], $reglement['document_id']))) ?>" class="font-medium text-marine-600 hover:underline"><?= e($reglement['numero']) ?></a>
              <span class="ml-2 text-xs text-slate-400"><?= e($reglement['tiers_nom'] ?? 'Fournisseur') ?></span>
            </td>
            <td class="<?= CLASSES_TD ?> text-slate-500"><?= e(MODES_PAIEMENT[$reglement['mode']] ?? $reglement['mode']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap text-rose-600">− <?= e(montant($reglement['montant'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($depenses as $depense): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($quand($depense['date'])) ?></td>
            <td class="<?= CLASSES_TD ?>">
              <a href="<?= e(u('/depenses/' . $depense['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($depense['designation']) ?></a>
              <span class="ml-2 text-xs text-slate-400"><?= e($depense['categorie_nom'] ?? 'Dépense') ?></span>
            </td>
            <td class="<?= CLASSES_TD ?> text-slate-500"><?= e(MODES_PAIEMENT[$depense['mode']] ?? $depense['mode']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap text-rose-600">− <?= e(montant($depense['montant_paye'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
