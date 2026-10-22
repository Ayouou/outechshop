<?php
/** Console — journal de toutes les décisions de la plateforme. */

exigerConsole();

$q = (string) param('q', '');
$args = [];
$where = '1 = 1';

if ($q !== '') {
    $where .= ' AND (j.action LIKE ? OR j.detail LIKE ? OR j.acteur LIKE ? OR s.nom LIKE ?)';
    for ($i = 0; $i < 4; $i++) $args[] = '%' . $q . '%';
}

$total = (int) valeur(
    "SELECT COUNT(*) FROM saas_journal j LEFT JOIN saas_societe s ON s.id = j.societe_id WHERE $where", $args);

$page = max(1, (int) param('page', 1));
$parPage = 50;

$lignes = tous(
    "SELECT j.*, s.nom AS societe_nom, s.code AS societe_code
       FROM saas_journal j
       LEFT JOIN saas_societe s ON s.id = j.societe_id
      WHERE $where
      ORDER BY j.created_at DESC
      LIMIT $parPage OFFSET " . (($page - 1) * $parPage),
    $args);

pageConsole('Journal', function () use ($lignes, $q, $total, $page, $parPage) {
    echo enTetePage('Journal de la plateforme', nombre($total) . ' entrée' . ($total > 1 ? 's' : ''));
    ?>

    <form method="get" class="mb-4 flex flex-wrap items-center gap-2">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Action, société, détail…"
             class="<?= CLASSES_SAISIE ?> max-w-xs">
      <?= bouton('Rechercher', ['variante' => 'secondaire']) ?>
    </form>

    <?= carteDebut(null, ['sans_padding' => true]) ?>
      <?= tableauDebut(['Quand', 'Société', 'Action', 'Détail', 'Par', 'IP']) ?>
        <?php foreach ($lignes as $ligne): ?>
          <tr>
            <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e(dateHeureFr($ligne['created_at'])) ?></td>
            <td class="<?= CLASSES_TD ?>">
              <?php if ($ligne['societe_id'] && $ligne['societe_nom']): ?>
                <a class="text-marine-700 hover:underline" href="<?= e(u('/console/societes/' . $ligne['societe_id'])) ?>"><?= e($ligne['societe_nom']) ?></a>
              <?php else: ?>
                <span class="text-slate-400">plateforme</span>
              <?php endif; ?>
            </td>
            <td class="<?= CLASSES_TD ?>"><?= badge($ligne['action']) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= e($ligne['detail'] ?: '—') ?></td>
            <td class="<?= CLASSES_TD ?>"><?= e($ligne['acteur'] ?: '—') ?></td>
            <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-400"><?= e($ligne['ip'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lignes) echo ligneVide(6, 'Aucune entrée'); ?>
      <?= tableauFin() ?>
    <?= carteFin() ?>

    <?php if ($total > $parPage): ?>
      <div class="mt-4 flex items-center justify-between text-sm text-slate-600">
        <span>Page <?= $page ?> sur <?= (int) ceil($total / $parPage) ?></span>
        <div class="flex gap-2">
          <?php if ($page > 1) echo boutonLien('/console/journal?page=' . ($page - 1) . '&q=' . rawurlencode($q), 'Précédent', ['variante' => 'secondaire']); ?>
          <?php if ($page * $parPage < $total) echo boutonLien('/console/journal?page=' . ($page + 1) . '&q=' . rawurlencode($q), 'Suivant', ['variante' => 'secondaire']); ?>
        </div>
      </div>
    <?php endif; ?>
<?php });
