<?php
/** Tableau de bord des chèques et effets. */

exigerSession();

$parStatut = [];
foreach (tous('SELECT statut, COUNT(*) n, COALESCE(SUM(montant), 0) total FROM effet GROUP BY statut') as $ligne) {
    $parStatut[$ligne['statut']] = $ligne;
}

$prochains = tous(
    "SELECT e.*, t.nom AS tiers_nom, d.numero AS document_numero FROM effet e
       LEFT JOIN tiers t ON t.id = e.tiers_id
       LEFT JOIN document d ON d.id = e.document_id
      WHERE e.statut = 'EN_ATTENTE' ORDER BY e.date_echeance ASC LIMIT 12",
);

$parType = tous(
    'SELECT type, statut, COUNT(*) n, COALESCE(SUM(montant), 0) total FROM effet GROUP BY type, statut',
);

page('Chèques / Effets', function () use ($parStatut, $prochains, $parType) {
    echo enTetePage('Chèques / Effets', 'Vue consolidée par statut, type et échéance',
        boutonLien('/effets/nouveau', 'Ajouter', ['icone' => 'plus']));

    $cartes = [
        ['statut' => 'EN_ATTENTE', 'libelle' => 'À encaisser', 'classe' => 'bg-amber-50 ring-amber-200'],
        ['statut' => 'ENCAISSE', 'libelle' => 'Déjà encaissés', 'classe' => 'bg-emerald-50 ring-emerald-200'],
        ['statut' => 'REJETE', 'libelle' => 'Rejetés', 'classe' => 'bg-rose-50 ring-rose-200'],
        ['statut' => 'ANNULE', 'libelle' => 'Annulés', 'classe' => 'bg-slate-50 ring-slate-200'],
    ];
    ?>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($cartes as $carte): $v = $parStatut[$carte['statut']] ?? ['n' => 0, 'total' => 0]; ?>
        <div class="rounded-xl p-4 ring-1 ring-inset <?= e($carte['classe']) ?>">
          <p class="text-sm text-slate-600"><?= e($carte['libelle']) ?></p>
          <p class="mt-1 text-xl font-semibold text-slate-900"><?= e(montant($v['total'], devise())) ?></p>
          <p class="mt-0.5 text-xs text-slate-500"><?= (int) $v['n'] ?> pièce(s)</p>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
      <?= carteDebut('Prochaines échéances', ['sous_titre' => "En attente d'encaissement", 'sans_padding' => true]) ?>
        <?= tableauDebut(['Échéance', 'Type', 'Numéro', 'Tiers', ['libelle' => 'Montant', 'classe' => 'text-right']]) ?>
        <?php if (!$prochains) echo ligneVide(5, 'Aucun effet en attente'); ?>
        <?php foreach ($prochains as $effet): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($effet['date_echeance'])) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= e($effet['type'] === 'CHEQUE' ? 'Chèque' : 'Effet') ?></td>
            <td class="<?= CLASSES_TD ?>">
              <a href="<?= e(u('/effets/' . $effet['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($effet['numero']) ?></a>
            </td>
            <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($effet['tiers_nom'] ?? '—') ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($effet['montant'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <?= carteDebut('Répartition par type et statut', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Type', 'Statut', ['libelle' => 'Pièces', 'classe' => 'text-right'], ['libelle' => 'Montant', 'classe' => 'text-right']]) ?>
        <?php if (!$parType) echo ligneVide(4); ?>
        <?php foreach ($parType as $ligne): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?>"><?= e($ligne['type'] === 'CHEQUE' ? 'Chèque' : 'Effet') ?></td>
            <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_EFFET, $ligne['statut']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= (int) $ligne['n'] ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($ligne['total'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
