<?php
/**
 * Étiquettes code-barres imprimables.
 *
 * Le code CODE128 est tracé par JsBarcode (la même bibliothèque que
 * l'application d'origine, servie depuis `assets/` : rien à télécharger au
 * moment de l'impression).
 */

exigerSession();

$articles = tous(
    'SELECT id, nom, reference, code_barre, prix_vente FROM article
      WHERE actif = 1 ORDER BY nom LIMIT 300',
);

$preselection = (string) param('article', '');

page('Étiquettes code-barres', function () use ($articles, $preselection) {
    echo '<div class="sans-impression">'
        . enTetePage('Étiquettes code-barres', "Sélectionnez les articles puis lancez l'impression")
        . '</div>';

    $donnees = array_map(fn($a) => [
        'id'        => $a['id'],
        'nom'       => $a['nom'],
        'valeur'    => $a['code_barre'] ?: $a['reference'],
        'prix'      => montant($a['prix_vente'], devise()),
    ], $articles);
    ?>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70 sans-impression">
        <div>
          <label class="mb-1.5 block text-sm font-medium text-slate-700">Exemplaires par article</label>
          <input type="number" min="1" max="50" value="1" id="exemplaires" class="<?= CLASSES_SAISIE ?>">
        </div>

        <div>
          <p class="mb-2 text-sm font-medium text-slate-700">Articles</p>
          <div class="max-h-96 space-y-1 overflow-y-auto rounded-lg p-2 ring-1 ring-slate-200" id="choix">
            <?php foreach ($articles as $i => $article): ?>
              <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                <input type="checkbox" value="<?= e($article['id']) ?>"
                       <?= ($preselection ? $preselection === $article['id'] : $i === 0) ? 'checked' : '' ?>
                       class="size-4 rounded border-slate-300 text-marine-600 focus:ring-marine-500">
                <span class="truncate"><?= e($article['nom']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button type="button" onclick="window.print()" class="<?= classesBouton('primaire', 'w-full') ?>">
          <?= icone('imprimante', 'size-4') ?> Imprimer les étiquettes
        </button>
      </div>

      <div class="zone-impression rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70 lg:col-span-2">
        <div id="etiquettes" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4"></div>
        <p id="vide" class="py-16 text-center text-sm text-slate-400">Sélectionnez au moins un article.</p>
      </div>
    </div>

    <script src="<?= e(u('/assets/jsbarcode.js')) ?>"></script>
    <script>
      const ARTICLES = <?= json_encode($donnees, JSON_UNESCAPED_UNICODE) ?>;
      const choix = document.getElementById('choix');
      const zone = document.getElementById('etiquettes');
      const vide = document.getElementById('vide');
      const exemplaires = document.getElementById('exemplaires');

      function rendre() {
        const cochees = [...choix.querySelectorAll('input:checked')].map((c) => c.value);
        const n = Math.max(1, Math.min(50, Number(exemplaires.value) || 1));
        zone.innerHTML = '';

        const choisis = ARTICLES.filter((a) => cochees.includes(a.id));
        vide.style.display = choisis.length ? 'none' : '';

        for (const article of choisis) {
          for (let i = 0; i < n; i++) {
            const carte = document.createElement('div');
            carte.className = 'flex break-inside-avoid flex-col items-center gap-1 rounded border border-slate-200 p-2 text-center';
            carte.innerHTML =
              '<p class="line-clamp-2 text-[11px] leading-tight font-medium text-slate-800"></p>'
              + '<svg class="max-w-full"></svg>'
              + '<p class="text-xs font-semibold text-slate-900"></p>';
            carte.querySelector('p').textContent = article.nom;
            carte.querySelectorAll('p')[1].textContent = article.prix;
            zone.appendChild(carte);
            try {
              JsBarcode(carte.querySelector('svg'), article.valeur, {
                format: 'CODE128', width: 1.4, height: 42, fontSize: 12, margin: 0, displayValue: true,
              });
            } catch (e) {
              // Valeur non encodable : on laisse l'étiquette sans code-barres.
            }
          }
        }
      }

      choix.addEventListener('change', rendre);
      exemplaires.addEventListener('input', rendre);
      rendre();
    </script>
<?php });
