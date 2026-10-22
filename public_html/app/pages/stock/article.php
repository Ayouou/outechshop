<?php
/** Fiche article : stock, variantes, ajustement et mouvements. */

$session = exigerSession();
$id = $params['id'];

$article = un(
    'SELECT a.*, c.nom AS categorie_nom, m.nom AS marque_nom FROM article a
       LEFT JOIN categorie c ON c.id = a.categorie_id
       LEFT JOIN marque m ON m.id = a.marque_id
      WHERE a.id = ?',
    [$id],
);
if (!$article) introuvable('Article introuvable');

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'ajuster') {
        $quantite = champNombre('quantite');
        $note = (string) (champ('note') ?: 'Ajustement manuel');
        if ($quantite == 0) {
            $erreur = 'Saisissez une quantité non nulle (négative pour une sortie).';
        } else {
            transaction(fn() => appliquerMouvement([
                'article_id'  => $id,
                'variante_id' => ouNull(champ('variante_id')),
                'lieu_id'     => $session['lieu_id'],
                'type'        => 'AJUSTEMENT',
                'quantite'    => $quantite,
                'note'        => $note,
                'user_id'     => $session['user_id'],
            ]));
            rediriger('/stock/articles/' . $id, 'Stock ajusté.');
        }
    }

    if ($action === 'variante') {
        $varianteId = ouNull(champ('variante_id'));
        $nom = (string) champ('nom', '');
        $valeurAttr = (string) champ('valeur', '');

        // Un champ prix vide signifie « hériter de l'article », d'où le null.
        $prix = function (string $cle) {
            $brut = trim((string) ($_POST[$cle] ?? ''));
            if ($brut === '') return null;
            $nombre = nombreSaisi($brut, -1);
            return $nombre >= 0 ? $nombre : null;
        };

        if ($nom === '') $erreur = "L'attribut est obligatoire (Taille, Couleur…)";
        elseif ($valeurAttr === '') $erreur = 'La valeur est obligatoire (M, Rouge…)';
        else {
            $donnees = [
                'nom'        => $nom,
                'valeur'     => $valeurAttr,
                'reference'  => (string) champ('reference', '') ?: $article['reference'] . '-' . mb_strtoupper(slugifier($valeurAttr)),
                'code_barre' => ouNull(champ('code_barre')),
                'prix_vente' => $prix('prix_vente'),
                'prix_achat' => $prix('prix_achat'),
                'actif'      => champBool('actif') ? 1 : 0,
            ];
            $stockInitial = champNombre('stock_initial');

            try {
                transaction(function () use ($varianteId, $donnees, $id, $article, $stockInitial, $session) {
                    if ($varianteId) {
                        modifier('article_variante', $varianteId, $donnees);
                        return;
                    }

                    $nouvelleId = inserer('article_variante', $donnees + ['article_id' => $id]);

                    // La création d'une variante fait passer l'article en VARIABLE.
                    modifier('article', $id, ['type' => 'VARIABLE']);

                    if ($stockInitial != 0 && $article['gere_stock']) {
                        appliquerMouvement([
                            'article_id'  => $id,
                            'variante_id' => $nouvelleId,
                            'lieu_id'     => $session['lieu_id'],
                            'type'        => 'AJUSTEMENT',
                            'quantite'    => $stockInitial,
                            'note'        => "Stock initial — {$donnees['nom']} : {$donnees['valeur']}",
                            'user_id'     => $session['user_id'],
                        ]);
                    }
                });
                rediriger('/stock/articles/' . $id, 'Variante enregistrée.');
            } catch (PDOException $e) {
                $erreur = 'Référence, code-barres ou combinaison attribut/valeur déjà utilisés.';
            }
        }
    }

    if ($action === 'supprimer-variante') {
        $varianteId = (string) champ('variante_id', '');
        $utilisee = (int) valeur('SELECT COUNT(*) FROM ligne_document WHERE variante_id = ?', [$varianteId]);
        if ($utilisee > 0) {
            // Variante déjà vendue : on la désactive pour préserver l'historique.
            modifier('article_variante', $varianteId, ['actif' => 0]);
        } else {
            supprimer('article_variante', $varianteId);
        }
        $restantes = (int) valeur('SELECT COUNT(*) FROM article_variante WHERE article_id = ?', [$id]);
        if ($restantes === 0) modifier('article', $id, ['type' => 'SIMPLE']);
        rediriger('/stock/articles/' . $id, 'Variante retirée.');
    }
}

$stocks = tous(
    'SELECT s.*, l.nom AS lieu_nom, v.nom AS variante_nom, v.valeur AS variante_valeur
       FROM stock s
       JOIN lieu_affaire l ON l.id = s.lieu_id
       LEFT JOIN article_variante v ON v.id = s.variante_id
      WHERE s.article_id = ? ORDER BY l.nom',
    [$id],
);

$variantes = tous(
    'SELECT v.*, (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s WHERE s.variante_id = v.id) AS stock
       FROM article_variante v WHERE v.article_id = ? ORDER BY v.nom, v.valeur',
    [$id],
);

$mouvements = tous(
    'SELECT m.*, l.nom AS lieu_nom, v.nom AS variante_nom, v.valeur AS variante_valeur
       FROM mouvement_stock m
       JOIN lieu_affaire l ON l.id = m.lieu_id
       LEFT JOIN article_variante v ON v.id = m.variante_id
      WHERE m.article_id = ? ORDER BY m.created_at DESC LIMIT 30',
    [$id],
);

$stockTotal = quantiteStock($stocks);
$marge = num($article['prix_vente']) - num($article['prix_achat']);
$margePourcent = num($article['prix_achat']) > 0 ? ($marge / num($article['prix_achat'])) * 100 : 0;

page('Fiche article', function () use ($article, $stocks, $variantes, $mouvements, $stockTotal, $marge, $margePourcent, $erreur) {
    echo enTetePage(
        $article['nom'],
        $article['reference'] . ($article['code_barre'] ? ' · ' . $article['code_barre'] : ''),
        boutonLien('/stock/codes-barres?article=' . $article['id'], 'Étiquette', ['variante' => 'secondaire', 'icone' => 'etiquette'])
        . boutonLien('/stock/articles/' . $article['id'] . '/modifier', 'Modifier', ['icone' => 'crayon']),
    );

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    ?>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?= tuile('Stock total', nombre($stockTotal) . ' ' . $article['unite']) ?>
      <?= tuile("Prix d'achat HT", montant($article['prix_achat'], devise())) ?>
      <?= tuile('Prix de vente HT', montant($article['prix_vente'], devise())) ?>
      <?= tuile('Marge unitaire', montant($marge, devise()), ['couleur' => 'text-emerald-600', 'detail' => nombre($margePourcent) . ' %']) ?>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-5">
        <?php if ($article['image_url']): ?>
          <?= carteDebut('Photo') ?>
            <img src="<?= e(urlImage($article['image_url'])) ?>" alt="<?= e($article['nom']) ?>" class="mx-auto max-h-64 rounded-lg object-contain">
          <?= carteFin() ?>
        <?php endif; ?>

        <?= carteDebut("Stock par lieu d'affaires", ['sans_padding' => true]) ?>
          <?= tableauDebut(['Lieu', ['libelle' => 'Quantité', 'classe' => 'text-right']]) ?>
          <?php if (!$stocks) echo ligneVide(2, 'Aucun stock'); ?>
          <?php foreach ($stocks as $stock): ?>
            <tr>
              <td class="<?= CLASSES_TD ?>">
                <?= e($stock['lieu_nom']) ?>
                <span class="block text-xs text-slate-400">
                  <?= e($stock['variante_id'] ? $stock['variante_nom'] . ' : ' . $stock['variante_valeur'] : 'Article sans variante') ?>
                </span>
              </td>
              <td class="<?= CLASSES_TD ?> text-right font-medium"><?= e(nombre($stock['quantite']) . ' ' . $article['unite']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>

        <?= carteDebut('Détails') ?>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between border-b border-slate-100 pb-2"><dt class="text-slate-500">Catégorie</dt><dd class="font-medium"><?= e($article['categorie_nom'] ?? '—') ?></dd></div>
            <div class="flex justify-between border-b border-slate-100 pb-2"><dt class="text-slate-500">Marque</dt><dd class="font-medium"><?= e($article['marque_nom'] ?? '—') ?></dd></div>
            <div class="flex justify-between border-b border-slate-100 pb-2"><dt class="text-slate-500">TVA</dt><dd class="font-medium"><?= e(pourcent($article['tva'])) ?></dd></div>
            <div class="flex justify-between border-b border-slate-100 pb-2"><dt class="text-slate-500">Seuil d'alerte</dt><dd class="font-medium"><?= e(nombre($article['stock_alerte'])) ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Statut</dt><dd><?= badge($article['actif'] ? 'Actif' : 'Inactif', $article['actif'] ? 'vert' : 'gris') ?></dd></div>
          </dl>
        <?= carteFin() ?>

        <?php if ($article['gere_stock']): ?>
          <?= carteDebut('Ajuster le stock', ['sous_titre' => "Lieu d'affaires courant"]) ?>
            <form method="post" class="space-y-3">
              <?= champCsrf() ?>
              <input type="hidden" name="_action" value="ajuster">
              <?php if ($variantes): ?>
                <?php $optionsVar = ['' => '— Article sans variante —'];
                foreach ($variantes as $v) $optionsVar[$v['id']] = $v['nom'] . ' : ' . $v['valeur']; ?>
                <?= champBloc('Variante', listeDeroulante('variante_id', $optionsVar, '')) ?>
              <?php endif; ?>
              <?= champBloc('Quantité', saisie('quantite', '', ['type' => 'number', 'attrs' => ['step' => '0.001', 'required' => true]]), ['aide' => 'Négative pour une sortie']) ?>
              <?= champBloc('Note', saisie('note', '', ['attrs' => ['placeholder' => 'Ajustement manuel']])) ?>
              <?= bouton('Appliquer', ['classe' => 'w-full']) ?>
            </form>
          <?= carteFin() ?>
        <?php endif; ?>
      </div>

      <div class="space-y-5 lg:col-span-2">
        <?= carteDebut('Variantes', ['sous_titre' => 'Déclinaisons vendables (taille, couleur…) avec leur propre stock', 'sans_padding' => true]) ?>
          <?= tableauDebut(['Attribut', 'Valeur', 'Référence', ['libelle' => 'Prix de vente', 'classe' => 'text-right'], ['libelle' => 'Stock', 'classe' => 'text-right'], 'Statut', '']) ?>
          <?php if (!$variantes) echo ligneVide(7, 'Aucune variante — l\'article se vend tel quel'); ?>
          <?php foreach ($variantes as $variante): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> font-medium"><?= e($variante['nom']) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($variante['valeur']) ?></td>
              <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($variante['reference']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap">
                <?= e(montant($variante['prix_vente'] ?? $article['prix_vente'], devise())) ?>
                <?php if ($variante['prix_vente'] === null): ?><span class="block text-[11px] text-slate-400">hérité</span><?php endif; ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($variante['stock'])) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= badge($variante['actif'] ? 'Active' : 'Inactive', $variante['actif'] ? 'vert' : 'gris') ?></td>
              <td class="<?= CLASSES_TD ?> text-right">
                <?= menuActions([
                    actionMenu('supprimer-variante', ['variante_id' => $variante['id']], 'Retirer', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => "Retirer la variante « {$variante['nom']} : {$variante['valeur']} » ?",
                    ]),
                ]) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>

          <div class="border-t border-slate-100 p-5">
            <p class="mb-3 text-sm font-medium text-slate-700">Ajouter une variante</p>
            <form method="post" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <?= champCsrf() ?>
              <input type="hidden" name="_action" value="variante">
              <?= champBloc('Attribut', saisie('nom', '', ['attrs' => ['placeholder' => 'Taille', 'required' => true]])) ?>
              <?= champBloc('Valeur', saisie('valeur', '', ['attrs' => ['placeholder' => 'M', 'required' => true]])) ?>
              <?= champBloc('Référence', saisie('reference', ''), ['aide' => 'Générée si vide']) ?>
              <?= champBloc('Code-barres', saisie('code_barre', '')) ?>
              <?= champBloc('Prix de vente', saisie('prix_vente', '', ['type' => 'number', 'attrs' => ['step' => '0.01']]), ['aide' => "Vide = prix de l'article"]) ?>
              <?= champBloc('Stock initial', saisie('stock_initial', '0', ['type' => 'number', 'attrs' => ['step' => '0.001']])) ?>
              <div class="sm:col-span-3 flex items-center justify-between">
                <?= caseACocher('actif', true, 'Variante active') ?>
                <?= bouton('Ajouter la variante', ['icone' => 'plus']) ?>
              </div>
            </form>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Mouvements de stock', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Date', 'Type', 'Variante', 'Lieu', ['libelle' => 'Quantité', 'classe' => 'text-right'], ['libelle' => 'Stock après', 'classe' => 'text-right'], 'Note']) ?>
          <?php if (!$mouvements) echo ligneVide(7); ?>
          <?php foreach ($mouvements as $mouvement): $q = num($mouvement['quantite']); ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateHeureFr($mouvement['created_at'])) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e(TYPES_MOUVEMENT[$mouvement['type']] ?? $mouvement['type']) ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($mouvement['variante_id'] ? $mouvement['variante_nom'] . ' : ' . $mouvement['variante_valeur'] : '—') ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($mouvement['lieu_nom']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right font-medium <?= $q >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= e(($q > 0 ? '+' : '') . nombre($q)) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($mouvement['stock_apres'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($mouvement['note'] ?: ($mouvement['reference'] ?: '—')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
