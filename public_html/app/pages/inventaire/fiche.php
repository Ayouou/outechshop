<?php
/** Comptage d'un inventaire, puis clôture (les écarts partent au stock). */

$session = exigerSession();

$id = $params['id'];
$inventaire = un(
    'SELECT i.*, l.nom AS lieu_nom, us.nom AS user_nom FROM inventaire i
       JOIN lieu_affaire l ON l.id = i.lieu_id
       LEFT JOIN utilisateur us ON us.id = i.user_id WHERE i.id = ?',
    [$id],
);
if (!$inventaire) introuvable('Inventaire introuvable');

$message = null;

if (estPost() && !$inventaire['cloture']) {
    $action = (string) champ('_action', '');

    if ($action === 'comptage') {
        $lignes = tous('SELECT id, quantite_theorique FROM ligne_inventaire WHERE inventaire_id = ?', [$id]);

        transaction(function () use ($lignes) {
            foreach ($lignes as $ligne) {
                if (!isset($_POST['ligne-' . $ligne['id']])) continue;
                $reelle = nombreSaisi($_POST['ligne-' . $ligne['id']], num($ligne['quantite_theorique']));
                modifier('ligne_inventaire', $ligne['id'], [
                    'quantite_reelle' => $reelle,
                    'ecart'           => $reelle - num($ligne['quantite_theorique']),
                ]);
            }
        });

        rediriger('/inventaire/' . $id, 'Comptage enregistré.');
    }

    if ($action === 'cloturer') {
        $lignes = tous('SELECT * FROM ligne_inventaire WHERE inventaire_id = ?', [$id]);

        transaction(function () use ($lignes, $inventaire, $id, $session) {
            foreach ($lignes as $ligne) {
                $ecart = num($ligne['ecart']);
                if ($ecart == 0) continue;

                appliquerMouvement([
                    'article_id'  => $ligne['article_id'],
                    'variante_id' => $ligne['variante_id'],
                    'lieu_id'     => $inventaire['lieu_id'],
                    'type'        => 'INVENTAIRE',
                    'quantite'    => $ecart,
                    'reference'   => $inventaire['numero'],
                    'note'        => "Écart d'inventaire physique",
                    'user_id'     => $session['user_id'],
                ]);
            }
            modifier('inventaire', $id, ['cloture' => 1]);
        });

        rediriger('/inventaire/' . $id, 'Inventaire clôturé : les écarts sont appliqués au stock.');
    }
}

$lignes = tous(
    'SELECT li.*, a.nom AS article_nom, a.reference, a.unite,
            v.nom AS variante_nom, v.valeur AS variante_valeur
       FROM ligne_inventaire li
       JOIN article a ON a.id = li.article_id
       LEFT JOIN article_variante v ON v.id = li.variante_id
      WHERE li.inventaire_id = ? ORDER BY a.nom',
    [$id],
);

$ecarts = 0;
foreach ($lignes as $ligne) {
    if (num($ligne['ecart']) != 0) $ecarts++;
}

page('Inventaire ' . $inventaire['numero'], function () use ($inventaire, $lignes, $ecarts) {
    $actions = boutonLien('/inventaire', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']);
    if (!$inventaire['cloture']) {
        $actions .= actionBouton('cloturer', [], "Clôturer et appliquer au stock", [
            'variante' => 'succes', 'icone' => 'coche',
            'confirmation' => "Clôturer l'inventaire ? Les écarts seront appliqués au stock, l'opération n'est pas réversible.",
        ]);
    }

    echo enTetePage(
        'Inventaire ' . $inventaire['numero'],
        dateFr($inventaire['date']) . ' · ' . $inventaire['lieu_nom'] . ' · ' . $ecarts . ' écart(s)',
        $actions,
    );

    if ($inventaire['cloture']) {
        echo '<div class="mb-4">' . alerte('Cet inventaire est clôturé : les écarts ont été appliqués au stock.', 'succes') . '</div>';
    }
    ?>

    <form method="post">
      <?= champCsrf() ?>
      <input type="hidden" name="_action" value="comptage">

      <?= carteDebut('Comptage', ['sous_titre' => 'Saisissez les quantités réellement comptées', 'sans_padding' => true]) ?>
        <?= tableauDebut(['Article', 'Référence',
            ['libelle' => 'Théorique', 'classe' => 'text-right'],
            ['libelle' => 'Compté', 'classe' => 'text-right'],
            ['libelle' => 'Écart', 'classe' => 'text-right']]) ?>
        <?php if (!$lignes) echo ligneVide(5); ?>
        <?php foreach ($lignes as $ligne): $ecart = num($ligne['ecart']); ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?>">
              <?= e($ligne['article_nom']) ?>
              <?php if ($ligne['variante_id']): ?>
                <span class="block text-xs text-slate-400"><?= e($ligne['variante_nom'] . ' : ' . $ligne['variante_valeur']) ?></span>
              <?php endif; ?>
            </td>
            <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($ligne['reference']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right text-slate-500"><?= e(nombre($ligne['quantite_theorique']) . ' ' . $ligne['unite']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right">
              <input type="number" step="0.001" name="ligne-<?= e($ligne['id']) ?>"
                     value="<?= e(num($ligne['quantite_reelle'])) ?>"
                     <?= $inventaire['cloture'] ? 'disabled' : '' ?>
                     class="<?= CLASSES_SAISIE ?> ml-auto max-w-28 text-right">
            </td>
            <td class="<?= CLASSES_TD ?> text-right font-medium <?= $ecart > 0 ? 'text-emerald-600' : ($ecart < 0 ? 'text-rose-600' : 'text-slate-400') ?>">
              <?= e(($ecart > 0 ? '+' : '') . nombre($ecart)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>

        <?php if (!$inventaire['cloture']): ?>
          <div class="flex justify-end border-t border-slate-100 px-5 py-3">
            <?= bouton('Enregistrer le comptage') ?>
          </div>
        <?php endif; ?>
      <?= carteFin() ?>
    </form>
<?php });
