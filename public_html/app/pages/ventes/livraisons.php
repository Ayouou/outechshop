<?php
/** Suivi logistique des bons de livraison. */

$session = exigerSession();

require_once __DIR__ . '/livraisons-fonctions.php';

if (estPost() && champ('_action') === 'statut') {
    $documentId = (string) champ('document_id', '');
    $statut = (string) champ('statut', '');
    if (isset(STATUTS_LIVRAISON[$statut])) {
        $existante = un('SELECT * FROM livraison WHERE document_id = ?', [$documentId]);
        enregistrerLivraison($documentId, [
            'statut'          => $statut,
            'transporteur'    => $existante['transporteur'] ?? null,
            'numero_suivi'    => $existante['numero_suivi'] ?? null,
            'adresse'         => $existante['adresse'] ?? null,
            'contact'         => $existante['contact'] ?? null,
            'frais_livraison' => $existante['frais_livraison'] ?? 0,
            'date_expedition' => $existante['date_expedition'] ?? null,
            'date_livraison'  => $existante['date_livraison'] ?? null,
            'note'            => $existante['note'] ?? null,
        ]);
    }
    rediriger('/ventes/livraisons', 'Suivi mis à jour.');
}

$params = lireParams();
$args = [$session['lieu_id']];
$conditions = ["d.type = 'BON_LIVRAISON'", 'd.lieu_id = ?'];

$statutFiltre = (string) param('statut', '');
if (isset(STATUTS_LIVRAISON[$statutFiltre])) {
    $conditions[] = 'COALESCE(lv.statut, \'EN_PREPARATION\') = ?';
    $args[] = $statutFiltre;
}

$recherche = rechercheSur(['d.numero', 't.nom', 'lv.transporteur', 'lv.numero_suivi'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur(
    "SELECT COUNT(*) FROM document d
       LEFT JOIN livraison lv ON lv.document_id = d.id
       LEFT JOIN tiers t ON t.id = d.tiers_id WHERE $where",
    $args,
);

$livraisons = tous(
    "SELECT d.id, d.numero, d.date, d.total_ttc, t.nom AS tiers_nom,
            lv.statut, lv.transporteur, lv.numero_suivi, lv.date_expedition, lv.date_livraison
       FROM document d
       LEFT JOIN livraison lv ON lv.document_id = d.id
       LEFT JOIN tiers t ON t.id = d.tiers_id
      WHERE $where ORDER BY d.date DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

page('Gestion des livraisons', function () use ($livraisons, $total, $params, $statutFiltre) {
    echo enTetePage('Gestion des livraisons', 'Transporteur, numéro de suivi, expédition et livraison');

    echo carteDebut(null, ['sans_padding' => true]);

    $filtres = '<select name="statut" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Tous les statuts</option>';
    foreach (STATUTS_LIVRAISON as $cle => $s) {
        $filtres .= '<option value="' . e($cle) . '"' . ($statutFiltre === $cle ? ' selected' : '') . '>' . e($s['label']) . '</option>';
    }
    $filtres .= '</select>';

    echo barreOutils('/ventes/livraisons', $params, $filtres, 'Bon, client, transporteur, suivi…');

    echo tableauDebut(['Action', 'Bon', 'Date', 'Client', 'Transporteur', 'Suivi',
        'Expédié', 'Livré', ['libelle' => 'Total', 'classe' => 'text-right'], 'Statut']);

    if (!$livraisons) echo ligneVide(10, 'Aucun bon de livraison');

    foreach ($livraisons as $ligne) {
        $statut = $ligne['statut'] ?? 'EN_PREPARATION';
        $elements = [lienMenu('/ventes/livraisons/' . $ligne['id'], 'Suivi détaillé', 'crayon')];
        foreach (STATUTS_LIVRAISON as $cle => $s) {
            if ($cle === $statut) continue;
            $elements[] = actionMenu('statut', ['document_id' => $ligne['id'], 'statut' => $cle],
                'Marquer « ' . $s['label'] . ' »', ['icone' => 'coche']);
        }
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>"><?= menuActions($elements) ?></td>
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u('/ventes/bons-livraison/' . $ligne['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($ligne['numero']) ?></a>
          </td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($ligne['date'])) ?></td>
          <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($ligne['tiers_nom'] ?? 'Client de passage') ?></td>
          <td class="<?= CLASSES_TD ?>"><?= e($ligne['transporteur'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($ligne['numero_suivi'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($ligne['date_expedition'] ? dateFr($ligne['date_expedition']) : '—') ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($ligne['date_livraison'] ? dateFr($ligne['date_livraison']) : '—') ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($ligne['total_ttc'], devise())) ?></td>
          <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_LIVRAISON, $statut) ?></td>
        </tr>
    <?php }

    echo tableauFin();
    echo pagination('/ventes/livraisons', $total, $params);
    echo carteFin();
});
