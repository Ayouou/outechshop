<?php
/** Liste des articles. Port de `stock/articles/page.tsx`. */

$session = exigerSession();

if (estPost() && champ('_action') === 'supprimer') {
    $id = (string) champ('id', '');
    $utilise = (int) valeur('SELECT COUNT(*) FROM ligne_document WHERE article_id = ?', [$id]);
    if ($utilise > 0) {
        // Article déjà vendu : on le désactive, la photo reste utile à l'historique.
        modifier('article', $id, ['actif' => 0]);
        rediriger('/stock/articles', 'Article déjà utilisé : il a été désactivé.', 'attention');
    }
    $image = valeur('SELECT image_url FROM article WHERE id = ?', [$id]);
    supprimer('article', $id);
    supprimerImage($image);
    rediriger('/stock/articles', 'Article supprimé.');
}

$params = lireParams();
$args = [];
$where = assembler([rechercheSur(['a.nom', 'a.reference', 'a.code_barre', 'a.description'], $params['q'], $args)]);

$total = (int) valeur("SELECT COUNT(*) FROM article a WHERE $where", $args);

$args[] = $session['lieu_id'];
$articles = tous(
    "SELECT a.*, c.nom AS categorie_nom, m.nom AS marque_nom,
            (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s WHERE s.article_id = a.id AND s.lieu_id = ?) AS quantite
       FROM article a
       LEFT JOIN categorie c ON c.id = a.categorie_id
       LEFT JOIN marque m ON m.id = a.marque_id
      WHERE $where ORDER BY a.created_at DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

page('Articles', function () use ($articles, $total, $params) {
    echo enTetePage('Gestion des stocks', 'Gérez vos produits',
        boutonLien('/stock/codes-barres', 'Étiquettes', ['variante' => 'secondaire', 'icone' => 'etiquette'])
        . boutonLien('/stock/articles/nouveau', 'Ajouter', ['icone' => 'plus']));

    echo carteDebut(null, ['sans_padding' => true]);
    echo barreOutils('/stock/articles', $params,
        boutonLien('/api/export/articles', 'Exporter', ['variante' => 'secondaire', 'icone' => 'telecharger']),
        'Nom, référence, code-barres…');

    echo tableauDebut([
        'Action', 'Article', 'Référence', 'Catégorie', 'Marque',
        ['libelle' => "Prix d'achat", 'classe' => 'text-right'],
        ['libelle' => 'Prix de vente', 'classe' => 'text-right'],
        ['libelle' => 'TVA', 'classe' => 'text-right'],
        ['libelle' => 'Stock actuel', 'classe' => 'text-right'],
    ]);

    if (!$articles) echo ligneVide(9);

    foreach ($articles as $article) {
        $quantite = num($article['quantite']);
        $alerte = $article['gere_stock'] && $quantite <= num($article['stock_alerte']);
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>">
            <?= menuActions([
                lienMenu('/stock/articles/' . $article['id'], 'Voir', 'oeil'),
                lienMenu('/stock/articles/' . $article['id'] . '/modifier', 'Modifier', 'crayon'),
                lienMenu('/stock/codes-barres?article=' . $article['id'], 'Étiquette', 'etiquette'),
                actionMenu('supprimer', ['id' => $article['id']], 'Effacer', [
                    'icone' => 'poubelle', 'classe' => 'text-rose-600',
                    'confirmation' => "Supprimer « {$article['nom']} » ?",
                ]),
            ]) ?>
          </td>
          <td class="<?= CLASSES_TD ?>">
            <div class="flex items-center gap-3">
              <span class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-50 ring-1 ring-slate-200">
                <?php if ($article['image_url']): ?>
                  <img src="<?= e(urlImage($article['image_url'])) ?>" alt="" loading="lazy" class="size-full object-cover">
                <?php else: ?>
                  <span class="text-slate-300"><?= icone('boite', 'size-4') ?></span>
                <?php endif; ?>
              </span>
              <span>
                <a href="<?= e(u('/stock/articles/' . $article['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($article['nom']) ?></a>
                <?php if (!$article['actif']) echo '<span class="ml-2">' . badge('Inactif', 'gris') . '</span>'; ?>
              </span>
            </div>
          </td>
          <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500">
            <?= e($article['reference']) ?>
            <?php if ($article['code_barre']): ?><span class="block text-[11px] text-slate-400"><?= e($article['code_barre']) ?></span><?php endif; ?>
          </td>
          <td class="<?= CLASSES_TD ?>"><?= e($article['categorie_nom'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?>"><?= e($article['marque_nom'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($article['prix_achat'], devise())) ?></td>
          <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($article['prix_vente'], devise())) ?></td>
          <td class="<?= CLASSES_TD ?> text-right"><?= e(pourcent($article['tva'])) ?></td>
          <td class="<?= CLASSES_TD ?> text-right">
            <?php if ($article['gere_stock']): ?>
              <span class="<?= $alerte ? 'font-semibold text-rose-600' : '' ?>"><?= e(nombre($quantite) . ' ' . $article['unite']) ?></span>
            <?php else: ?>
              <span class="text-slate-400">Non géré</span>
            <?php endif; ?>
          </td>
        </tr>
    <?php }

    echo tableauFin();
    echo pagination('/stock/articles', $total, $params);
    echo carteFin();
});
