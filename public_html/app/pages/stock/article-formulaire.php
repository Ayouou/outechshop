<?php
/** Création / modification d'un article. Port de `FormulaireArticle.tsx`. */

$session = exigerSession();

$id = $params['id'] ?? null;
$article = null;

if ($id) {
    $article = un('SELECT * FROM article WHERE id = ?', [$id]);
    if (!$article) introuvable('Article introuvable');
}

$categories = tous('SELECT id, nom FROM categorie ORDER BY nom');
$marques = tous('SELECT id, nom FROM marque ORDER BY nom');

// « Mètre » et « Mètre carré » servent aux supports d'impression vendus au
// rouleau : c'est l'unité dans laquelle l'atelier déclare ses consommations.
const UNITES = ['Pièce', 'Kg', 'Litre', 'Mètre', 'Mètre carré', 'Rouleau', 'Feuille', 'Carton', 'Paquet', 'Heure'];

$erreur = null;
$v = $article ?? [
    'reference' => '', 'code_barre' => '', 'nom' => '', 'description' => '',
    'unite' => 'Pièce', 'prix_achat' => '0', 'prix_vente' => '0', 'tva' => (string) num(societe()['tva_defaut']),
    'stock_alerte' => '0', 'categorie_id' => '', 'marque_id' => '', 'image_url' => null,
    'laize' => '0', 'gere_stock' => 1, 'actif' => 1,
];

if (estPost()) {
    $donnees = [
        'reference'    => (string) champ('reference', ''),
        'code_barre'   => ouNull(champ('code_barre')),
        'nom'          => (string) champ('nom', ''),
        'description'  => ouNull(champ('description')),
        'unite'        => (string) (champ('unite') ?: 'Pièce'),
        'prix_achat'   => max(0, champNombre('prix_achat')),
        'prix_vente'   => max(0, champNombre('prix_vente')),
        'tva'          => min(100, max(0, champNombre('tva'))),
        'stock_alerte' => max(0, champNombre('stock_alerte')),
        'laize'        => max(0, champNombre('laize')),
        'categorie_id' => ouNull(champ('categorie_id')),
        'marque_id'    => ouNull(champ('marque_id')),
        'gere_stock'   => champBool('gere_stock') ? 1 : 0,
        'actif'        => champBool('actif') ? 1 : 0,
    ];
    $stockInitial = champNombre('stock_initial');
    $v = array_merge($v, $donnees);

    if (mb_strlen($donnees['nom']) < 2) {
        $erreur = "Le nom de l'article est obligatoire";
    } else {
        try {
            $imageUrl = appliquerImage($article['image_url'] ?? null, $donnees['nom']);
        } catch (RuntimeException $e) {
            $imageUrl = false;
            $erreur = $e->getMessage();
        }

        if (!$erreur) {
            // `false` quand la photo n'a pas bougé : la colonne n'est pas touchée.
            if ($imageUrl !== false) $donnees['image_url'] = $imageUrl;

            try {
                if ($article) {
                    if ($donnees['reference'] === '') unset($donnees['reference']);
                    modifier('article', $article['id'], $donnees);
                    rediriger('/stock/articles/' . $article['id'], 'Article enregistré.');
                }

                $donnees['reference'] = $donnees['reference'] ?: str_replace('/', '-', numeroSuivant('ART'));

                $nouveau = transaction(function () use ($donnees, $stockInitial, $session) {
                    $articleId = inserer('article', $donnees);

                    if ($stockInitial != 0 && $donnees['gere_stock']) {
                        appliquerMouvement([
                            'article_id' => $articleId,
                            'lieu_id'    => $session['lieu_id'],
                            'type'       => 'AJUSTEMENT',
                            'quantite'   => $stockInitial,
                            'note'       => 'Stock initial',
                            'user_id'    => $session['user_id'],
                        ]);
                    }
                    return $articleId;
                });

                rediriger('/stock/articles/' . $nouveau, 'Article créé.');
            } catch (PDOException $e) {
                // L'article n'a pas été créé : la photo stockée n'aurait plus de porteur.
                if (!$article && $imageUrl) supprimerImage($imageUrl);
                $erreur = 'Référence ou code-barres déjà utilisé.';
            }
        }
    }
}

page($article ? "Modifier l'article" : 'Nouvel article', function () use ($article, $v, $erreur, $categories, $marques) {
    $modification = (bool) $article;

    echo enTetePage(
        $modification ? 'Modifier « ' . $article['nom'] . ' »' : 'Nouvel article',
        'Fiche produit, prix et stock',
        boutonLien($modification ? '/stock/articles/' . $article['id'] : '/stock/articles', 'Retour',
            ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    $optionsCategories = ['' => '— Aucune —'];
    foreach ($categories as $c) $optionsCategories[$c['id']] = $c['nom'];
    $optionsMarques = ['' => '— Aucune —'];
    foreach ($marques as $m) $optionsMarques[$m['id']] = $m['nom'];
    $optionsTva = [];
    foreach (TAUX_TVA as $t) $optionsTva[$t] = $t . ' %';
    $optionsUnites = array_combine(UNITES, UNITES);
    ?>

    <form method="post" class="space-y-5">
      <?= champCsrf() ?>
      <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

      <?= carteDebut('Identification') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?= champBloc("Nom de l'article", saisie('nom', $v['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true, 'classe' => 'sm:col-span-2']) ?>
          <?= champBloc('Référence / SKU', saisie('reference', $v['reference']), $modification ? [] : ['aide' => 'Généré si vide']) ?>
          <?= champBloc('Code-barres', saisie('code_barre', $v['code_barre'])) ?>
          <?= champBloc('Catégorie', listeDeroulante('categorie_id', $optionsCategories, $v['categorie_id'])) ?>
          <?= champBloc('Marque', listeDeroulante('marque_id', $optionsMarques, $v['marque_id'])) ?>
          <?= champBloc('Description', zoneTexte('description', $v['description']), ['classe' => 'sm:col-span-2 lg:col-span-3']) ?>
        </div>
      <?= carteFin() ?>

      <?= carteDebut('Photo', ['sous_titre' => 'Affichée dans la liste des articles et sur la caisse']) ?>
        <?= champImage($v['image_url'] ?? null) ?>
      <?= carteFin() ?>

      <?= carteDebut('Prix et stock') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?= champBloc("Prix d'achat HT (" . devise() . ')', saisie('prix_achat', $v['prix_achat'], ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0]])) ?>
          <?= champBloc('Prix de vente HT (' . devise() . ')', saisie('prix_vente', $v['prix_vente'], ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0]]), ['obligatoire' => true]) ?>
          <?= champBloc('TVA (%)', listeDeroulante('tva', $optionsTva, (string) (int) num($v['tva']))) ?>
          <?= champBloc('Unité', listeDeroulante('unite', $optionsUnites, $v['unite'])) ?>
          <?= champBloc("Seuil d'alerte", saisie('stock_alerte', $v['stock_alerte'], ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]])) ?>
          <?= champBloc('Laize (m)', saisie('laize', num($v['laize'] ?? 0), ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]]),
              ['aide' => "Largeur du rouleau, pour l'imprimerie. 0 si sans objet."]) ?>
          <?php if (!$modification) echo champBloc('Stock initial', saisie('stock_initial', '0', ['type' => 'number', 'attrs' => ['step' => '0.001']]), ['aide' => "Enregistré comme ajustement d'inventaire"]); ?>
        </div>

        <div class="mt-4 flex flex-wrap gap-6">
          <?= caseACocher('gere_stock', (bool) $v['gere_stock'], 'Gérer le stock de cet article') ?>
          <?= caseACocher('actif', (bool) $v['actif'], 'Article actif') ?>
        </div>
      <?= carteFin() ?>

      <div class="flex justify-end"><?= bouton($modification ? 'Enregistrer' : "Créer l'article") ?></div>
    </form>
<?php });
