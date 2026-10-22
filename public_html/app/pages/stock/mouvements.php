<?php
/** Journal des mouvements de stock du lieu courant. */

$session = exigerSession();
$params = lireParams();

$args = [$session['lieu_id']];
$conditions = ['m.lieu_id = ?'];
$recherche = rechercheSur(['a.nom', 'a.reference'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur(
    "SELECT COUNT(*) FROM mouvement_stock m JOIN article a ON a.id = m.article_id WHERE $where",
    $args,
);

$mouvements = tous(
    "SELECT m.*, a.nom AS article_nom, d.numero AS document_numero, us.nom AS user_nom
       FROM mouvement_stock m
       JOIN article a ON a.id = m.article_id
       LEFT JOIN document d ON d.id = m.document_id
       LEFT JOIN utilisateur us ON us.id = m.user_id
      WHERE $where ORDER BY m.created_at DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

page('Mouvements de stock', function () use ($mouvements, $total, $params) {
    echo enTetePage('Mouvements de stock', 'Historique complet des entrées et sorties');

    echo carteDebut(null, ['sans_padding' => true]);
    echo barreOutils('/stock/mouvements', $params,
        boutonLien('/api/export/mouvements', 'Exporter', ['variante' => 'secondaire', 'icone' => 'telecharger']),
        'Article, référence…');

    echo tableauDebut(['Date', 'Article', 'Type', 'Document',
        ['libelle' => 'Quantité', 'classe' => 'text-right'],
        ['libelle' => 'Stock après', 'classe' => 'text-right'], 'Utilisateur']);

    if (!$mouvements) echo ligneVide(7);

    foreach ($mouvements as $mouvement) { $q = num($mouvement['quantite']); ?>
      <tr class="hover:bg-slate-50">
        <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateHeureFr($mouvement['created_at'])) ?></td>
        <td class="<?= CLASSES_TD ?>">
          <a href="<?= e(u('/stock/articles/' . $mouvement['article_id'])) ?>" class="text-marine-600 hover:underline"><?= e($mouvement['article_nom']) ?></a>
        </td>
        <td class="<?= CLASSES_TD ?>"><?= e(TYPES_MOUVEMENT[$mouvement['type']] ?? $mouvement['type']) ?></td>
        <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($mouvement['document_numero'] ?: ($mouvement['reference'] ?: '—')) ?></td>
        <td class="<?= CLASSES_TD ?> text-right font-medium <?= $q >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= e(($q > 0 ? '+' : '') . nombre($q)) ?></td>
        <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($mouvement['stock_apres'])) ?></td>
        <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($mouvement['user_nom'] ?? '—') ?></td>
      </tr>
    <?php }

    echo tableauFin();
    echo pagination('/stock/mouvements', $total, $params);
    echo carteFin();
});
