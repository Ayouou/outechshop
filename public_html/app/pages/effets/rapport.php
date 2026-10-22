<?php
/** Rapport consolidé : toutes les pièces, chèques et effets confondus. */

exigerSession();

$params = lireParams();
$args = [];
$conditions = [];

$statutFiltre = (string) param('statut', '');
if (isset(STATUTS_EFFET[$statutFiltre])) {
    $conditions[] = 'e.statut = ?';
    $args[] = $statutFiltre;
}

$typeFiltre = (string) param('piece', '');
if (in_array($typeFiltre, ['CHEQUE', 'EFFET'], true)) {
    $conditions[] = 'e.type = ?';
    $args[] = $typeFiltre;
}

$recherche = rechercheSur(['e.numero', 'e.banque', 't.nom'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur("SELECT COUNT(*) FROM effet e LEFT JOIN tiers t ON t.id = e.tiers_id WHERE $where", $args);
$cumul = num(valeur("SELECT COALESCE(SUM(e.montant), 0) FROM effet e LEFT JOIN tiers t ON t.id = e.tiers_id WHERE $where", $args));

$effets = tous(
    "SELECT e.*, t.nom AS tiers_nom, d.numero AS document_numero, d.type AS document_type
       FROM effet e
       LEFT JOIN tiers t ON t.id = e.tiers_id
       LEFT JOIN document d ON d.id = e.document_id
      WHERE $where ORDER BY e.date_echeance DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

page('Rapport chèque / effet', function () use ($effets, $total, $cumul, $params, $statutFiltre, $typeFiltre) {
    echo enTetePage('Rapport chèque / effet', "$total pièce(s) · " . montant($cumul, devise()),
        boutonLien('/api/export/effets', 'Exporter', ['variante' => 'secondaire', 'icone' => 'telecharger']));

    echo carteDebut(null, ['sans_padding' => true]);

    $filtres = '<select name="piece" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Chèques et effets</option>';
    foreach (['CHEQUE' => 'Chèques', 'EFFET' => 'Effets'] as $cle => $libelle) {
        $filtres .= '<option value="' . e($cle) . '"' . ($typeFiltre === $cle ? ' selected' : '') . '>' . e($libelle) . '</option>';
    }
    $filtres .= '</select><select name="statut" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Tous les statuts</option>';
    foreach (STATUTS_EFFET as $cle => $s) {
        $filtres .= '<option value="' . e($cle) . '"' . ($statutFiltre === $cle ? ' selected' : '') . '>' . e($s['label']) . '</option>';
    }
    $filtres .= '</select>';

    echo barreOutils('/effets/rapport', $params, $filtres, 'Numéro, banque, tiers…');

    echo tableauDebut(['Échéance', 'Type', 'Numéro', 'Tiers', 'Banque', 'Document',
        ['libelle' => 'Montant', 'classe' => 'text-right'], 'Encaissement', 'Statut']);

    if (!$effets) echo ligneVide(9);

    foreach ($effets as $effet) { ?>
      <tr class="hover:bg-slate-50">
        <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($effet['date_echeance'])) ?></td>
        <td class="<?= CLASSES_TD ?>"><?= e($effet['type'] === 'CHEQUE' ? 'Chèque' : 'Effet') ?></td>
        <td class="<?= CLASSES_TD ?>">
          <a href="<?= e(u('/effets/' . $effet['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($effet['numero']) ?></a>
        </td>
        <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($effet['tiers_nom'] ?? '—') ?></td>
        <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($effet['banque'] ?? '—') ?></td>
        <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500">
          <?php if ($effet['document_id']): ?>
            <a href="<?= e(u(urlDocument($effet['document_type'], $effet['document_id']))) ?>" class="hover:underline"><?= e($effet['document_numero']) ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($effet['montant'], devise())) ?></td>
        <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($effet['date_encaissement'] ? dateFr($effet['date_encaissement']) : '—') ?></td>
        <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_EFFET, $effet['statut']) ?></td>
      </tr>
    <?php }

    echo tableauFin();
    echo pagination('/effets/rapport', $total, $params);
    echo carteFin();
});
