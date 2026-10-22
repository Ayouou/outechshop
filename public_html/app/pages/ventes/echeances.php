<?php
/**
 * Échéances : ce qui reste à encaisser.
 *
 * Les bons de livraison partis à crédit sont aussi des créances : ils figurent
 * ici tant qu'ils n'ont pas été regroupés en facture.
 */

$session = exigerSession();
$params = lireParams();

$where = 'd.lieu_id = ? AND (' . conditionVentesReelles('d') . ") AND d.statut_paiement <> 'PAYE'";
$args = [$session['lieu_id']];

$total = (int) valeur("SELECT COUNT(*) FROM document d WHERE $where", $args);
$totalDu = num(valeur("SELECT COALESCE(SUM(d.total_ttc - d.montant_paye), 0) FROM document d WHERE $where", $args));

$documents = tous(
    "SELECT d.*, t.nom AS tiers_nom, t.telephone FROM document d
       LEFT JOIN tiers t ON t.id = d.tiers_id
      WHERE $where ORDER BY d.date_echeance IS NULL, d.date_echeance ASC, d.date ASC
      LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

page('Échéances', function () use ($documents, $total, $totalDu, $params) {
    echo enTetePage('Échéances', montant($totalDu, devise()) . ' à encaisser sur ' . $total . ' document(s)');

    echo carteDebut(null, ['sans_padding' => true]);
    echo tableauDebut(['Échéance', 'Document', 'Client', 'Téléphone',
        ['libelle' => 'Total', 'classe' => 'text-right'],
        ['libelle' => 'Payé', 'classe' => 'text-right'],
        ['libelle' => 'Reste', 'classe' => 'text-right'], 'Statut']);

    if (!$documents) echo ligneVide(8, 'Aucune échéance en attente');

    $aujourdhui = date('Y-m-d');

    foreach ($documents as $document) {
        $reste = num($document['total_ttc']) - num($document['montant_paye']);
        $enRetard = $document['date_echeance'] && substr((string) $document['date_echeance'], 0, 10) < $aujourdhui;
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?> whitespace-nowrap">
            <?php if ($document['date_echeance']): ?>
              <span class="<?= $enRetard ? 'font-medium text-rose-600' : '' ?>"><?= e(dateFr($document['date_echeance'])) ?></span>
              <?php if ($enRetard) echo ' ' . badge('En retard', 'rouge'); ?>
            <?php else: ?>
              <span class="text-slate-400">—</span>
            <?php endif; ?>
          </td>
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u(urlDocument($document['type'], $document['id']))) ?>" class="font-medium text-marine-600 hover:underline"><?= e($document['numero']) ?></a>
            <span class="block text-xs text-slate-400"><?= e(meta($document['type'])['label']) ?></span>
          </td>
          <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($document['tiers_nom'] ?? 'Client de passage') ?></td>
          <td class="<?= CLASSES_TD ?> text-slate-500 whitespace-nowrap"><?= e($document['telephone'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($document['total_ttc'], devise())) ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($document['montant_paye'], devise())) ?></td>
          <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap text-rose-600"><?= e(montant($reste, devise())) ?></td>
          <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_PAIEMENT, $document['statut_paiement']) ?></td>
        </tr>
    <?php }

    echo tableauFin();
    echo pagination('/ventes/echeances', $total, $params);
    echo carteFin();
});
