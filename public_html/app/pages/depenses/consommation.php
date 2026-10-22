<?php
/**
 * Consommation de stock : déclarer le papier, le toner, l'encre partis à l'usage.
 *
 * L'écran fait deux choses. En haut on déclare ce qui a été consommé — la
 * matière quitte le stock et la valeur est enregistrée en dépense. En bas, le
 * journal des déclarations, avec de quoi en reprendre une si on s'est trompé.
 *
 * Le prix proposé vient de la fiche article, mais il se corrige ligne par
 * ligne : c'est le prix corrigé qui est figé sur la déclaration.
 */

$session = exigerSession();

$erreur = null;
$lignesInitiales = [];

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        supprimerConsommationDepense((string) champ('id', ''));
        rediriger('/depenses/consommation', 'Déclaration reprise, la matière revient au stock.');
    }

    $lignesSaisies = lireLignesConsommation((string) ($_POST['lignes'] ?? '[]'));
    $resultat = enregistrerConsommationDepense($session, [
        'date'         => champ('date'),
        'categorie_id' => champ('categorie_id'),
        'designation'  => champ('designation'),
        'note'         => champ('note'),
    ], $lignesSaisies);

    if (isset($resultat['erreur'])) {
        $erreur = $resultat['erreur'];
        $lignesInitiales = $lignesSaisies; // la saisie revient telle quelle
    } else {
        rediriger('/depenses/consommation', 'Consommation enregistrée, le stock est à jour.');
    }
}

$params = lireParams();

$au = param('au') ? finJour(param('au')) : finJour('now');
$du = param('du') ? debutJour(param('du')) : debutJour(date('Y-m-01'));

$args = [$session['lieu_id'], $du, $au];
$conditions = ["d.origine = 'CONSOMMATION'", 'd.lieu_id = ?', 'd.date BETWEEN ? AND ?'];

$recherche = rechercheSur(['d.designation', 'd.numero', 'd.note'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur("SELECT COUNT(*) FROM depense d WHERE $where", $args);
$valeurPeriode = num(valeur("SELECT COALESCE(SUM(d.montant_ttc), 0) FROM depense d WHERE $where", $args));

$declarations = tous(
    "SELECT d.*, u.nom AS user_nom, c.nom AS categorie_nom,
            (SELECT COUNT(*) FROM depense_ligne dl WHERE dl.depense_id = d.id) AS nb_lignes
       FROM depense d
       LEFT JOIN utilisateur u ON u.id = d.user_id
       LEFT JOIN categorie_depense c ON c.id = d.categorie_id
      WHERE $where ORDER BY d.date DESC, d.created_at DESC
      LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

// Le détail des lignes, en une requête pour toute la page.
$details = [];
if ($declarations) {
    $p = [];
    $sql = 'SELECT dl.*, a.unite FROM depense_ligne dl
              LEFT JOIN article a ON a.id = dl.article_id
             WHERE dl.depense_id IN (' . clauseIn(array_column($declarations, 'id'), $p) . ')
             ORDER BY dl.ordre';
    foreach (tous($sql, $p) as $ligne) $details[$ligne['depense_id']][] = $ligne;
}

$consommables = articlesConsommables($session['lieu_id']);
$categories = tous('SELECT id, nom FROM categorie_depense ORDER BY nom');

// Ce qui passe sous son seuil d'alerte mérite d'être vu depuis cet écran :
// c'est ici qu'on s'aperçoit qu'il n'y a plus de papier.
$sousSeuil = 0;
foreach ($consommables as $article) {
    if (!$article['gere_stock']) continue;
    if (num($article['stock_alerte']) > 0 && num($article['stock']) <= num($article['stock_alerte'])) $sousSeuil++;
}

page('Consommation de stock', function () use (
    $declarations, $details, $total, $params, $consommables, $categories,
    $erreur, $lignesInitiales, $valeurPeriode, $sousSeuil, $du, $au
) {
    echo enTetePage('Consommation de stock',
        'Papier, toner, encre… ce qui part à l’usage, valorisé au prix d’achat',
        boutonLien('/depenses', 'Toutes les dépenses', ['variante' => 'secondaire', 'icone' => 'fleche-g']));
    ?>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <?= tuile('Valeur consommée', montant($valeurPeriode, devise()),
          ['icone' => 'portefeuille', 'detail' => 'Sur la période affichée']) ?>
      <?= tuile('Déclarations', (string) $total, ['icone' => 'boite', 'detail' => 'Sorties enregistrées']) ?>
      <?= tuile('Sous le seuil d’alerte', (string) $sousSeuil, [
          'icone' => 'alerte',
          'couleur' => $sousSeuil > 0 ? 'text-amber-600' : 'text-slate-900',
          'detail' => $sousSeuil > 0 ? 'À racheter bientôt' : 'Rien à signaler',
      ]) ?>
    </div>

    <?php
    $optionsCategories = ['' => '— Aucune —'];
    foreach ($categories as $c) $optionsCategories[$c['id']] = $c['nom'];
    ?>

    <?= carteDebut('Déclarer une consommation', [
        'sous_titre' => 'La matière quitte le stock et sa valeur passe en dépense',
    ]) ?>
      <form method="post" id="form-conso">
        <?= champCsrf() ?>
        <input type="hidden" name="lignes" id="lignes-json" value="">

        <?php if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>'; ?>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <?= champBloc('Date', saisie('date', dateInput('now'), ['type' => 'date'])) ?>
          <?= champBloc('Catégorie de dépense', listeDeroulante('categorie_id', $optionsCategories, '')) ?>
          <?= champBloc('Désignation', saisie('designation', '', [
              'attrs' => ['placeholder' => 'Laisser vide : reprise des consommables'],
          ]), ['classe' => 'sm:col-span-2']) ?>
        </div>

        <div class="mt-5 rounded-xl ring-1 ring-slate-200">
          <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
            <select id="ajout-article" class="<?= CLASSES_SAISIE ?> max-w-md">
              <option value="">— Ajouter un consommable —</option>
              <?php foreach ($consommables as $article): ?>
                <option value="<?= e($article['id']) ?>">
                  <?= e($article['nom']) ?>
                  <?= $article['gere_stock']
                      ? '— reste ' . e(nombre($article['stock']) . ' ' . $article['unite'])
                      : '— non suivi en stock' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-500">Le prix proposé vient de la fiche article — corrigez-le si besoin.</p>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr>
                  <th class="<?= CLASSES_TH ?>">Consommable</th>
                  <th class="<?= CLASSES_TH ?> text-right">Quantité</th>
                  <th class="<?= CLASSES_TH ?> text-right">Prix d’achat</th>
                  <th class="<?= CLASSES_TH ?> text-right">Valeur</th>
                  <th class="<?= CLASSES_TH ?>"></th>
                </tr>
              </thead>
              <tbody id="corps-lignes"></tbody>
            </table>
          </div>

          <div class="flex items-center justify-between gap-4 border-t border-slate-100 px-4 py-3">
            <?= zoneTexte('note', '', ['lignes' => 1, 'classe' => 'max-w-md', 'attrs' => ['placeholder' => 'Note (facultatif)']]) ?>
            <p class="text-sm text-slate-600">
              Total consommé : <strong id="total-conso" class="text-base text-marine-700">—</strong>
            </p>
          </div>
        </div>

        <div class="mt-4 flex justify-end"><?= bouton('Enregistrer la consommation', ['icone' => 'boite']) ?></div>
      </form>

      <script>
      (function () {
        const ARTICLES = <?= json_encode(array_map(fn($a) => [
            'id'         => $a['id'],
            'nom'        => $a['nom'],
            'unite'      => $a['unite'],
            'prix_achat' => num($a['prix_achat']),
            'stock'      => num($a['stock']),
            'gere_stock' => (int) $a['gere_stock'],
        ], $consommables), JSON_UNESCAPED_UNICODE) ?>;
        const DEVISE = <?= json_encode(devise()) ?>;

        const parArticle = Object.fromEntries(ARTICLES.map((a) => [a.id, a]));
        let lignes = <?= json_encode($lignesInitiales, JSON_UNESCAPED_UNICODE) ?>;
        lignes = lignes.map((l) => ({ ...l, cle: Math.random().toString(36).slice(2, 10) }));

        const nf = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const corps = document.getElementById('corps-lignes');
        const champAjout = document.getElementById('ajout-article');
        const champJson = document.getElementById('lignes-json');
        const affichageTotal = document.getElementById('total-conso');

        const arrondir = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
        const valeurLigne = (l) => arrondir((+l.quantite || 0) * (+l.prix_achat || 0));

        function rendre() {
          corps.innerHTML = '';

          if (lignes.length === 0) {
            corps.innerHTML = '<tr><td colspan="5" class="px-4 py-10 text-center text-slate-400">'
              + 'Aucun consommable — choisissez-en un ci-dessus.</td></tr>';
          }

          lignes.forEach((ligne) => {
            const article = parArticle[ligne.article_id] || {};
            const unite = article.unite || '';
            // Consommer plus que ce qui reste n'est pas interdit — la saisie peut
            // arriver après coup — mais ça se voit.
            const trop = article.gere_stock && (+ligne.quantite || 0) > (+article.stock || 0);

            const tr = document.createElement('tr');
            tr.className = 'border-t border-slate-100';
            tr.innerHTML =
              '<td class="px-4 py-2">'
              + '<span class="font-medium text-slate-800">' + ligne.designation + '</span>'
              + (article.gere_stock
                  ? '<span class="ml-2 text-xs ' + (trop ? 'font-medium text-rose-600' : 'text-slate-400') + '">reste '
                    + nf.format(+article.stock || 0) + ' ' + unite + '</span>'
                  : '<span class="ml-2 text-xs text-slate-400">non suivi en stock</span>')
              + '</td>'
              + '<td class="px-4 py-2"><input type="number" step="0.001" min="0" data-champ="quantite"'
              + ' class="w-28 rounded border-0 px-2 py-1.5 text-right ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-marine-500'
              + (trop ? ' ring-rose-300 text-rose-700' : '') + '"></td>'
              + '<td class="px-4 py-2"><input type="number" step="0.01" min="0" data-champ="prix_achat"'
              + ' class="w-28 rounded border-0 px-2 py-1.5 text-right ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-marine-500"></td>'
              + '<td data-role="valeur" class="px-4 py-2 text-right font-medium whitespace-nowrap">'
                + nf.format(valeurLigne(ligne)) + ' ' + DEVISE + '</td>'
              + '<td class="px-4 py-2 text-right"><button type="button" data-role="retirer"'
              + ' class="rounded p-1 text-rose-500 hover:bg-rose-50" aria-label="Retirer la ligne">✕</button></td>';

            tr.querySelector('[data-champ=quantite]').value = ligne.quantite;
            tr.querySelector('[data-champ=prix_achat]').value = ligne.prix_achat;

            const celluleValeur = tr.querySelector('[data-role=valeur]');

            tr.querySelectorAll('input').forEach((input) => {
              input.addEventListener('input', () => {
                ligne[input.dataset.champ] = parseFloat(String(input.value).replace(',', '.')) || 0;
                // La valeur de la ligne suit la frappe. On écrit dans la cellule
                // plutôt que de refaire le tableau : un rendu complet à chaque
                // touche ferait perdre le curseur.
                celluleValeur.textContent = nf.format(valeurLigne(ligne)) + ' ' + DEVISE;
                majTotal();
              });
              // Le rendu complet attend la sortie du champ : c'est là que
              // l'avertissement « plus que le stock » est recalculé.
              input.addEventListener('change', rendre);
            });

            tr.querySelector('[data-role=retirer]').addEventListener('click', () => {
              lignes = lignes.filter((l) => l.cle !== ligne.cle);
              rendre();
            });

            corps.appendChild(tr);
          });

          majTotal();
        }

        function majTotal() {
          const total = arrondir(lignes.reduce((s, l) => s + valeurLigne(l), 0));
          affichageTotal.textContent = nf.format(total) + ' ' + DEVISE;
          champJson.value = JSON.stringify(lignes.map(({ cle, ...l }) => l));
        }

        champAjout.addEventListener('change', () => {
          const article = parArticle[champAjout.value];
          champAjout.value = '';
          if (!article) return;

          // Le même consommable deux fois : on incrémente plutôt que d'empiler.
          const existante = lignes.find((l) => l.article_id === article.id);
          if (existante) {
            existante.quantite = (+existante.quantite || 0) + 1;
          } else {
            lignes.push({
              cle: Math.random().toString(36).slice(2, 10),
              article_id: article.id,
              variante_id: null,
              designation: article.nom,
              quantite: 1,
              prix_achat: article.prix_achat,
            });
          }
          rendre();
        });

        rendre();
      })();
      </script>
    <?= carteFin() ?>

    <div class="mt-5">
      <?= carteDebut(null, ['sans_padding' => true]) ?>
        <?php
        $filtres = '<input type="date" name="du" value="' . e(dateInput($du)) . '" class="' . CLASSES_SAISIE_AUTO . '">'
            . '<input type="date" name="au" value="' . e(dateInput($au)) . '" class="' . CLASSES_SAISIE_AUTO . '">';

        echo barreOutils('/depenses/consommation', $params, $filtres, 'Désignation, numéro, note…');

        echo tableauDebut(['Action', 'Date', 'Numéro', 'Désignation', 'Détail', 'Par',
            ['libelle' => 'Valeur', 'classe' => 'text-right']]);

        if (!$declarations) echo ligneVide(7, 'Aucune consommation sur la période');

        foreach ($declarations as $declaration) {
            $lignes = $details[$declaration['id']] ?? [];
            ?>
          <tr class="hover:bg-slate-50 align-top">
            <td class="<?= CLASSES_TD ?>">
              <?= menuActions([actionMenu('supprimer', ['id' => $declaration['id']], 'Reprendre', [
                  'icone' => 'poubelle', 'classe' => 'text-rose-600',
                  'confirmation' => 'Reprendre cette consommation ? La matière revient au stock.',
              ])]) ?>
            </td>
            <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($declaration['date'])) ?></td>
            <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($declaration['numero']) ?></td>
            <td class="<?= CLASSES_TD ?>">
              <span class="font-medium text-slate-800"><?= e($declaration['designation']) ?></span>
              <?php if ($declaration['categorie_nom']): ?>
                <span class="block text-xs text-slate-500"><?= e($declaration['categorie_nom']) ?></span>
              <?php endif; ?>
            </td>
            <td class="<?= CLASSES_TD ?> text-xs text-slate-500">
              <?php foreach ($lignes as $ligne): ?>
                <div class="whitespace-nowrap">
                  <?= e($ligne['designation']) ?> ·
                  <?= e(nombre($ligne['quantite']) . ' ' . ($ligne['unite'] ?? '')) ?>
                  × <?= e(montant($ligne['prix_achat'], devise())) ?>
                </div>
              <?php endforeach; ?>
              <?php if (!$lignes) echo '<span class="text-slate-400">—</span>'; ?>
            </td>
            <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($declaration['user_nom'] ?? '—') ?></td>
            <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap">
              <?= e(montant($declaration['montant_ttc'], devise())) ?>
            </td>
          </tr>
        <?php }

        echo tableauFin();
        echo pagination('/depenses/consommation', $total, $params);
        echo carteFin();
        ?>
    </div>
<?php });
