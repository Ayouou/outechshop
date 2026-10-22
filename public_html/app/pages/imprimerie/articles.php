<?php
/**
 * Catalogue de l'imprimerie : les supports que l'on consomme et les
 * prestations que l'on vend.
 *
 * Ce sont des articles ordinaires — ils vivent dans la même table que le
 * reste du stock, se vendent en caisse et se valorisent à l'inventaire. Ce
 * qui les distingue tient en trois colonnes : leur rôle dans l'atelier, le
 * support qu'une prestation consomme, et combien elle en consomme.
 */

$session = exigerRole('ADMIN', 'MANAGER');

$erreur = null;

// Le formulaire sert aussi à modifier : `?modifier=…` le remplit avec l'article.
$modifie = param('modifier')
    ? un("SELECT * FROM article WHERE id = ? AND type_impression <> 'AUCUN'", [param('modifier')])
    : null;

$v = $modifie ?? [
    'id' => '', 'reference' => '', 'nom' => '', 'type_impression' => 'SUPPORT',
    'unite' => 'Mètre', 'laize' => '0', 'support_id' => '', 'conso_unitaire' => '0',
    'prix_achat' => '0', 'prix_vente' => '0', 'tva' => (string) num(societe()['tva_defaut']),
    'stock_alerte' => '0', 'actif' => 1,
];

if (estPost()) {
    if (champ('_action') === 'retirer') {
        // On ne supprime pas un article rattaché à des documents : il sort de
        // l'atelier en repassant « marchandise ordinaire ».
        modifier('article', (string) champ('id', ''), ['type_impression' => 'AUCUN', 'support_id' => null]);
        rediriger('/imprimerie/articles', "Retiré du catalogue de l'imprimerie.");
    }

    $id = ouNull(champ('id'));
    $type = isset(TYPES_ARTICLE_IMPRESSION[(string) champ('type_impression')])
        ? (string) champ('type_impression') : 'SUPPORT';

    $donnees = [
        'nom'             => (string) champ('nom', ''),
        'type_impression' => $type,
        'unite'           => (string) (champ('unite') ?: 'Mètre'),
        'prix_achat'      => max(0, champNombre('prix_achat')),
        'prix_vente'      => max(0, champNombre('prix_vente')),
        'tva'             => min(100, max(0, champNombre('tva'))),
        'actif'           => champBool('actif') ? 1 : 0,
        // Un support se compte au rouleau : laize et seuil d'alerte. Une
        // prestation ne s'inventorie pas, elle consomme.
        'laize'           => $type === 'SUPPORT' ? max(0, champNombre('laize')) : 0,
        'stock_alerte'    => $type === 'SUPPORT' ? max(0, champNombre('stock_alerte')) : 0,
        'gere_stock'      => $type === 'SUPPORT' ? 1 : 0,
        'support_id'      => $type === 'PRESTATION' ? ouNull(champ('support_id')) : null,
        'conso_unitaire'  => $type === 'PRESTATION' ? max(0, champNombre('conso_unitaire')) : 0,
    ];

    $stockInitial = champNombre('stock_initial');
    $v = array_merge($v, $donnees, ['id' => $id]);

    if (mb_strlen($donnees['nom']) < 2) {
        $erreur = 'Le nom est obligatoire';
    } else {
        try {
            if ($id) {
                modifier('article', $id, $donnees);
                rediriger('/imprimerie/articles', 'Article enregistré.');
            }

            $reference = trim((string) champ('reference', ''))
                ?: str_replace('/', '-', numeroSuivant($type === 'SUPPORT' ? 'SUP' : 'PRE'));

            transaction(function () use ($donnees, $reference, $stockInitial, $session) {
                $articleId = inserer('article', $donnees + ['reference' => $reference]);

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
            });

            rediriger('/imprimerie/articles', 'Article ajouté au catalogue.');
        } catch (PDOException $e) {
            $erreur = 'Référence déjà utilisée.';
        }
    }
}

$articles = tous(
    "SELECT a.*, s.nom AS support_nom, s.unite AS support_unite,
            COALESCE((SELECT SUM(st.quantite) FROM stock st
                       WHERE st.article_id = a.id AND st.lieu_id = ?), 0) AS stock
       FROM article a
       LEFT JOIN article s ON s.id = a.support_id
      WHERE a.type_impression <> 'AUCUN'
      ORDER BY a.type_impression, a.nom",
    [$session['lieu_id']],
);

$supports = tous(
    "SELECT id, nom, unite, laize FROM article
      WHERE type_impression = 'SUPPORT' AND actif = 1 ORDER BY nom",
);

page("Catalogue de l'imprimerie", function () use ($articles, $supports, $v, $erreur, $modifie) {
    echo enTetePage("Supports & prestations",
        'Ce que l\'atelier consomme, et ce qu\'il vend',
        boutonLien('/stock/articles', 'Tous les articles', ['variante' => 'secondaire', 'icone' => 'boite']));

    $optionsSupports = ['' => '— Aucun —'];
    foreach ($supports as $support) {
        $optionsSupports[$support['id']] = $support['nom']
            . (num($support['laize']) > 0 ? ' (laize ' . nombre($support['laize']) . ' m)' : '');
    }

    $optionsUnites = [];
    foreach (['Mètre', 'Mètre carré', 'Pièce', 'Rouleau', 'Feuille'] as $unite) $optionsUnites[$unite] = $unite;

    $optionsTva = [];
    foreach (TAUX_TVA as $taux) $optionsTva[$taux] = $taux . ' %';
    ?>
    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
      <div class="xl:order-2 xl:self-start">
        <?= carteDebut($modifie ? 'Modifier' : 'Ajouter au catalogue') ?>
          <form method="post" class="space-y-4" id="formulaire-article">
            <?= champCsrf() ?>
            <input type="hidden" name="id" value="<?= e($v['id'] ?? '') ?>">
            <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

            <?= champBloc('Rôle dans l\'atelier',
                listeDeroulante('type_impression', TYPES_ARTICLE_IMPRESSION, $v['type_impression'], ['id' => 'type']),
                ['aide' => 'Le support est le rouleau ; la prestation est ce que paie le client']) ?>

            <?= champBloc('Nom', saisie('nom', $v['nom'], ['attrs' => ['required' => true,
                'placeholder' => 'Bâche 510 g — rouleau 3,20 m']]), ['obligatoire' => true]) ?>

            <?php if (!$modifie) echo champBloc('Référence', saisie('reference', ''),
                ['aide' => 'Laissée vide, elle est attribuée automatiquement']); ?>

            <?= champBloc('Unité', listeDeroulante('unite', $optionsUnites, $v['unite'])) ?>

            <div data-role="support" class="space-y-4">
              <?= champBloc('Laize (m)', saisie('laize', num($v['laize']),
                  ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]]),
                  ['aide' => 'Largeur du rouleau. Elle sert à calculer les lés.']) ?>
              <?= champBloc("Prix d'achat HT (" . devise() . ')', saisie('prix_achat', num($v['prix_achat']),
                  ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0]]),
                  ['aide' => 'Sert à chiffrer les chutes']) ?>
              <?= champBloc("Seuil d'alerte", saisie('stock_alerte', num($v['stock_alerte']),
                  ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]])) ?>
              <?php if (!$modifie) echo champBloc('Stock initial', saisie('stock_initial', '0',
                  ['type' => 'number', 'attrs' => ['step' => '0.001']]),
                  ['aide' => "Enregistré comme ajustement d'inventaire"]); ?>
            </div>

            <div data-role="prestation" class="space-y-4">
              <?= champBloc('Support consommé', listeDeroulante('support_id', $optionsSupports, $v['support_id']),
                  ['aide' => 'Ce que cette prestation prélève sur le stock']) ?>
              <?= champBloc('Consommation par unité', saisie('conso_unitaire', num($v['conso_unitaire']),
                  ['type' => 'number', 'attrs' => ['step' => '0.0001', 'min' => 0]]),
                  ['aide' => 'Laisser 0 pour déduire des dimensions et de la laize. À renseigner pour une vente à la pièce (ex. 0,35 m de film par t-shirt).']) ?>
            </div>

            <?= champBloc('Prix de vente HT (' . devise() . ')', saisie('prix_vente', num($v['prix_vente']),
                ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0]])) ?>
            <?= champBloc('TVA', listeDeroulante('tva', $optionsTva, (string) (int) num($v['tva']))) ?>
            <?= caseACocher('actif', (bool) $v['actif'], 'Actif') ?>

            <div class="flex gap-2">
              <?= bouton($modifie ? 'Enregistrer' : 'Ajouter', ['classe' => 'flex-1']) ?>
              <?php if ($modifie) echo boutonLien('/imprimerie/articles', 'Annuler', ['variante' => 'secondaire']); ?>
            </div>
          </form>

          <script>
            // Un support et une prestation ne se renseignent pas pareil : on ne
            // montre que les champs qui comptent pour le rôle choisi.
            (function () {
              const type = document.getElementById('type');
              const blocs = {
                SUPPORT: document.querySelector('[data-role=support]'),
                PRESTATION: document.querySelector('[data-role=prestation]'),
              };
              const maj = () => {
                blocs.SUPPORT.hidden = type.value !== 'SUPPORT';
                blocs.PRESTATION.hidden = type.value !== 'PRESTATION';
              };
              type.addEventListener('change', maj);
              maj();
            })();
          </script>
        <?= carteFin() ?>
      </div>

      <div class="xl:order-1 xl:col-span-2">
        <?= carteDebut('Le catalogue', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Action', 'Article', 'Rôle', 'Unité',
              ['libelle' => 'Laize', 'classe' => 'text-right'],
              ['libelle' => 'Stock', 'classe' => 'text-right'],
              'Support consommé',
              ['libelle' => 'Prix de vente', 'classe' => 'text-right'], 'État']) ?>
          <?php if (!$articles) echo ligneVide(9, 'Aucun support ni prestation — ajoutez-en un à droite'); ?>
          <?php foreach ($articles as $article):
            $estSupport = $article['type_impression'] === 'SUPPORT';
            $alerte = $estSupport && num($article['stock_alerte']) > 0
                && num($article['stock']) <= num($article['stock_alerte']); ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?= menuActions([
                    lienMenu('/imprimerie/articles?modifier=' . $article['id'], 'Modifier', 'crayon'),
                    lienMenu('/stock/articles/' . $article['id'], 'Fiche article', 'oeil'),
                    actionMenu('retirer', ['id' => $article['id']], 'Retirer du catalogue', [
                        'icone' => 'croix', 'classe' => 'text-amber-600',
                        'confirmation' => "Retirer « {$article['nom']} » du catalogue de l'imprimerie ? "
                            . "L'article reste au stock, il n'apparaît plus dans l'atelier.",
                    ]),
                ]) ?>
              </td>
              <td class="<?= CLASSES_TD ?>">
                <span class="font-medium text-slate-800"><?= e($article['nom']) ?></span>
                <span class="block font-mono text-xs text-slate-400"><?= e($article['reference']) ?></span>
              </td>
              <td class="<?= CLASSES_TD ?>">
                <?= $estSupport ? badge('Support', 'bleu') : badge('Prestation', 'violet') ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($article['unite']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap">
                <?= num($article['laize']) > 0 ? e(nombre($article['laize']) . ' m') : '—' ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= $alerte ? 'font-medium text-rose-600' : '' ?>">
                <?= $estSupport ? e(nombre($article['stock'])) : '—' ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-slate-500">
                <?php if ($article['support_nom']): ?>
                  <?= e($article['support_nom']) ?>
                  <?php if (num($article['conso_unitaire']) > 0): ?>
                    <span class="block text-xs text-slate-400">
                      <?= e(nombre($article['conso_unitaire']) . ' ' . $article['support_unite'] . ' / unité') ?>
                    </span>
                  <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap">
                <?= e(montant($article['prix_vente'], devise())) ?>
                <span class="block text-xs text-slate-400">/ <?= e($article['unite']) ?></span>
              </td>
              <td class="<?= CLASSES_TD ?>">
                <?= $article['actif'] ? badge('Actif', 'vert') : badge('Inactif', 'gris') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>

        <div class="mt-4">
          <?= alerte('<strong>Comment ça marche.</strong> Un <em>support</em> est le rouleau : bâche, vinyle, '
              . 'film DTF, papier — il porte le stock, la laize et le prix d\'achat. Une <em>prestation</em> '
              . 'est ce que paie le client : impression sur bâche, transfert DTF, lettrage. En lui désignant '
              . 'son support, chaque ordre de travail sait quoi prélever, et le stock baisse tout seul au '
              . 'passage en impression.', 'info') ?>
        </div>
      </div>
    </div>
<?php });
