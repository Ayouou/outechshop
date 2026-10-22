<?php
/** Liste des chèques (ou des effets), avec changement de statut. */

exigerSession();

$type = cheminDemande() === '/effets/cheques' ? 'CHEQUE' : 'EFFET';
$base = $type === 'CHEQUE' ? '/effets/cheques' : '/effets/effets';
$titre = $type === 'CHEQUE' ? 'Tous les chèques' : 'Tous les effets';

if (estPost()) {
    $action = (string) champ('_action', '');
    $id = (string) champ('id', '');

    if ($action === 'statut') {
        $statut = (string) champ('statut', '');
        if (isset(STATUTS_EFFET[$statut])) {
            modifier('effet', $id, [
                'statut'            => $statut,
                'date_encaissement' => $statut === 'ENCAISSE' ? maintenant() : null,
            ]);
        }
        rediriger($base, 'Statut mis à jour.');
    }

    if ($action === 'supprimer') {
        supprimer('effet', $id);
        rediriger($base, 'Pièce supprimée.');
    }
}

$params = lireParams();
$args = [$type];
$conditions = ['e.type = ?'];

$statutFiltre = (string) param('statut', '');
if (isset(STATUTS_EFFET[$statutFiltre])) {
    $conditions[] = 'e.statut = ?';
    $args[] = $statutFiltre;
}

$recherche = rechercheSur(['e.numero', 'e.banque', 't.nom'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur(
    "SELECT COUNT(*) FROM effet e LEFT JOIN tiers t ON t.id = e.tiers_id WHERE $where",
    $args,
);

$effets = tous(
    "SELECT e.*, t.nom AS tiers_nom, d.numero AS document_numero, d.type AS document_type
       FROM effet e
       LEFT JOIN tiers t ON t.id = e.tiers_id
       LEFT JOIN document d ON d.id = e.document_id
      WHERE $where ORDER BY e.date_echeance ASC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

page($titre, function () use ($titre, $type, $base, $effets, $total, $params, $statutFiltre) {
    echo enTetePage($titre, $type === 'CHEQUE' ? 'Chèques reçus et émis' : 'Effets de commerce',
        boutonLien('/effets/nouveau?type=' . $type, 'Ajouter', ['icone' => 'plus']));

    echo carteDebut(null, ['sans_padding' => true]);

    $options = '<select name="statut" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Tous les statuts</option>';
    foreach (STATUTS_EFFET as $cle => $s) {
        $options .= '<option value="' . e($cle) . '"' . ($statutFiltre === $cle ? ' selected' : '') . '>' . e($s['label']) . '</option>';
    }
    $options .= '</select>';

    echo barreOutils($base, $params, $options, 'Numéro, banque, tiers…');

    echo tableauDebut(['Action', 'Échéance', 'Numéro', 'Tiers', 'Banque', 'Sens',
        ['libelle' => 'Montant', 'classe' => 'text-right'], 'Document', 'Statut']);

    if (!$effets) echo ligneVide(9);

    foreach ($effets as $effet) {
        $elements = [lienMenu('/effets/' . $effet['id'], 'Modifier', 'crayon')];
        foreach (['ENCAISSE' => 'Marquer encaissé', 'REJETE' => 'Marquer rejeté',
                  'EN_ATTENTE' => 'Remettre en attente', 'ANNULE' => 'Annuler'] as $statut => $libelle) {
            if ($effet['statut'] === $statut) continue;
            $elements[] = actionMenu('statut', ['id' => $effet['id'], 'statut' => $statut], $libelle, ['icone' => 'coche']);
        }
        $elements[] = actionMenu('supprimer', ['id' => $effet['id']], 'Effacer', [
            'icone' => 'poubelle', 'classe' => 'text-rose-600',
            'confirmation' => "Supprimer la pièce n° {$effet['numero']} ?",
        ]);
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>"><?= menuActions($elements) ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($effet['date_echeance'])) ?></td>
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u('/effets/' . $effet['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($effet['numero']) ?></a>
          </td>
          <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($effet['tiers_nom'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($effet['banque'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?>"><?= badge($effet['sens'] === 'RECU' ? 'Reçu' : 'Émis', $effet['sens'] === 'RECU' ? 'vert' : 'orange') ?></td>
          <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($effet['montant'], devise())) ?></td>
          <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500">
            <?php if ($effet['document_id']): ?>
              <a href="<?= e(u(urlDocument($effet['document_type'], $effet['document_id']))) ?>" class="hover:underline"><?= e($effet['document_numero']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_EFFET, $effet['statut']) ?></td>
        </tr>
    <?php }

    echo tableauFin();
    echo pagination($base, $total, $params);
    echo carteFin();
});
