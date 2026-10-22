<?php
/** Inventaires physiques du lieu courant. */

$session = exigerSession();

$erreur = null;

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        $id = (string) champ('id', '');
        $inventaire = un('SELECT cloture FROM inventaire WHERE id = ?', [$id]);
        // Un inventaire clôturé a déjà touché le stock : il reste comme trace.
        if ($inventaire && !$inventaire['cloture']) supprimer('inventaire', $id);
        rediriger('/inventaire', 'Inventaire supprimé.');
    }

    // Création : fige les quantités théoriques du lieu courant.
    $articles = tous(
        'SELECT a.id, (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s
                        WHERE s.article_id = a.id AND s.lieu_id = ? AND s.variante_id IS NULL) AS theorique
           FROM article a WHERE a.actif = 1 AND a.gere_stock = 1 ORDER BY a.nom',
        [$session['lieu_id']],
    );

    if (!$articles) {
        $erreur = 'Aucun article géré en stock à inventorier.';
    } else {
        $nouveau = transaction(function () use ($articles, $session) {
            $inventaireId = inserer('inventaire', [
                'numero'  => numeroSuivant('IN'),
                'date'    => maintenant(),
                'lieu_id' => $session['lieu_id'],
                'user_id' => $session['user_id'],
                'note'    => ouNull(champ('note')),
            ]);

            insererLot('ligne_inventaire', array_map(fn($article) => [
                'inventaire_id'      => $inventaireId,
                'article_id'         => $article['id'],
                'quantite_theorique' => num($article['theorique']),
                'quantite_reelle'    => num($article['theorique']),
                'ecart'              => 0,
            ], $articles));

            return $inventaireId;
        });

        rediriger('/inventaire/' . $nouveau, 'Inventaire créé : les quantités sont figées.');
    }
}

$inventaires = tous(
    'SELECT i.*, us.nom AS user_nom,
            (SELECT COUNT(*) FROM ligne_inventaire l WHERE l.inventaire_id = i.id) AS lignes,
            (SELECT COUNT(*) FROM ligne_inventaire l WHERE l.inventaire_id = i.id AND l.ecart <> 0) AS ecarts
       FROM inventaire i
       LEFT JOIN utilisateur us ON us.id = i.user_id
      WHERE i.lieu_id = ? ORDER BY i.date DESC',
    [$session['lieu_id']],
);

page('Inventaire physique', function () use ($inventaires, $erreur) {
    echo enTetePage('Inventaire physique', 'Comptage figé, écarts appliqués au stock à la clôture');

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:order-2 lg:self-start">
        <?= carteDebut('Nouvel inventaire', ['sous_titre' => 'Les quantités théoriques sont figées à la création']) ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <?= champBloc('Note', zoneTexte('note', '', ['lignes' => 2, 'attrs' => ['placeholder' => 'Inventaire de fin de mois…']])) ?>
            <?= bouton("Démarrer l'inventaire", ['classe' => 'w-full', 'icone' => 'presse']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:order-1 lg:col-span-2">
        <?= carteDebut('Historique', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Numéro', 'Date', 'Opérateur', ['libelle' => 'Articles', 'classe' => 'text-right'],
              ['libelle' => 'Écarts', 'classe' => 'text-right'], 'Statut', '']) ?>
          <?php if (!$inventaires) echo ligneVide(7, 'Aucun inventaire'); ?>
          <?php foreach ($inventaires as $inventaire): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <a href="<?= e(u('/inventaire/' . $inventaire['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($inventaire['numero']) ?></a>
              </td>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($inventaire['date'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($inventaire['user_nom'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $inventaire['lignes'] ?></td>
              <td class="<?= CLASSES_TD ?> text-right <?= $inventaire['ecarts'] > 0 ? 'font-medium text-amber-600' : '' ?>"><?= (int) $inventaire['ecarts'] ?></td>
              <td class="<?= CLASSES_TD ?>"><?= badge($inventaire['cloture'] ? 'Clôturé' : 'En cours', $inventaire['cloture'] ? 'vert' : 'orange') ?></td>
              <td class="<?= CLASSES_TD ?> text-right">
                <?php if (!$inventaire['cloture']): ?>
                  <?= actionBouton('supprimer', ['id' => $inventaire['id']], 'Effacer', [
                      'variante' => 'fantome', 'classe' => 'text-rose-600',
                      'confirmation' => "Supprimer l'inventaire {$inventaire['numero']} ?",
                  ]) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
