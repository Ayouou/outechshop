<?php
/**
 * Comptoir impression : la vente rapide de l'imprimerie.
 *
 * Un client entre, demande deux mètres de DTF ou une bâche 3 × 2 : on tape les
 * dimensions, le prix se calcule, il paie, il repart avec son ticket. Pas de
 * dossier d'atelier — rien à suivre, c'est fait sur-le-champ.
 *
 * Ce que le comptoir fait, et que la caisse ordinaire ne sait pas faire :
 * il **compte au m² et au mètre** à partir des dimensions, et il **sort du
 * rouleau** la matière que la vente a consommée.
 */

$session = exigerRole(...ROLES_IMPRIMERIE_GESTION);

if (estPost()) {
    $lignes = lireLignesTravail((string) ($_POST['lignes'] ?? '[]'));

    if (!$lignes) {
        rediriger('/imprimerie/comptoir', 'Ajoutez au moins une ligne.', 'erreur');
    }

    $totaux = totauxTravail($lignes, champNombre('remise'));
    $paye = max(0, min(champNombre('montant_paye'), $totaux['total_ttc']));

    // La pièce remise au client est un bon de livraison encaissé, comme au
    // point de vente : la facture viendra plus tard s'il la demande.
    $lignesDocument = [];
    foreach ($totaux['lignes'] as $ligne) {
        $detail = $ligne['mode'] === 'PIECE' ? ''
            : ' (' . nombre($ligne['largeur']) . ' × ' . nombre($ligne['hauteur']) . ' m'
                . (num($ligne['quantite']) > 1 ? ' × ' . nombre($ligne['quantite']) : '') . ')';

        $lignesDocument[] = [
            // La matière sort par la consommation, jamais par le document : la
            // compter ici la retirerait deux fois du stock.
            'article_id'    => null,
            'variante_id'   => null,
            'designation'   => $ligne['designation'] . $detail,
            'quantite'      => $ligne['unites'],
            'prix_unitaire' => $ligne['prix_unitaire'],
            'remise'        => $ligne['remise'],
            'tva'           => $ligne['tva'],
        ];
    }

    $resultat = creerDocument($session, 'BON_LIVRAISON', [
        'source'        => 'POS',
        'tiers_id'      => champ('tiers_id'),
        'date'          => maintenant(),
        'notes'         => ouNull(champ('notes')),
        'remise'        => $totaux['remise'],
        'montant_paye'  => $paye,
        'mode_paiement' => champ('mode_paiement') ?: 'ESPECES',
    ], $lignesDocument);

    if (isset($resultat['erreur'])) {
        rediriger('/imprimerie/comptoir', $resultat['erreur'], 'erreur');
    }

    $numero = valeur('SELECT numero FROM document WHERE id = ?', [$resultat['id']]);

    foreach ($totaux['lignes'] as $ligne) {
        if (empty($ligne['support_id']) || num($ligne['conso_prevue']) <= 0) continue;
        enregistrerConsommation($session, [
            'article_id' => $ligne['support_id'],
            'quantite'   => num($ligne['conso_prevue']),
            'chute'      => 0,
            'motif'      => 'PRODUCTION',
            'note'       => 'Comptoir ' . $numero . ' — ' . $ligne['designation'],
        ]);
    }

    // On file droit sur le ticket : c'est lui la preuve de la vente, un message
    // posé ici ne serait affiché par aucune page d'impression.
    rediriger('/impression/' . $resultat['id'] . '?format=80');
}

$prestations = tous(
    "SELECT id, nom, unite, prix_vente, tva, support_id, conso_unitaire, type_impression
       FROM article
      WHERE actif = 1 AND type_impression <> 'AUCUN'
      ORDER BY type_impression = 'PRESTATION' DESC, nom",
);

$supports = tous(
    "SELECT a.id, a.nom, a.unite, a.laize,
            COALESCE((SELECT SUM(s.quantite) FROM stock s
                       WHERE s.article_id = a.id AND s.lieu_id = ?), 0) AS stock
       FROM article a
      WHERE a.actif = 1 AND a.gere_stock = 1
        AND (a.type_impression = 'SUPPORT' OR a.laize > 0)
      ORDER BY a.nom",
    [$session['lieu_id']],
);

$clients = tous(
    "SELECT id, nom, nom_entreprise FROM tiers
      WHERE actif = 1 AND type IN ('CLIENT', 'LES_DEUX') ORDER BY nom",
);

page('Comptoir impression', function () use ($prestations, $supports, $clients) {
    echo enTetePage('Comptoir impression',
        'Vente au mètre, au m² ou à la pièce — encaissée sur-le-champ',
        boutonLien('/imprimerie/travaux/nouveau', 'Ouvrir un dossier plutôt',
            ['variante' => 'secondaire', 'icone' => 'flux']));

    if (!$prestations) {
        echo alerte('Aucune prestation au catalogue de l\'imprimerie. '
            . '<a class="underline" href="' . e(u('/imprimerie/articles')) . '">Ajoutez-en une</a> '
            . 'pour vendre au comptoir.', 'attention');
        return;
    }

    $optionsClients = ['' => 'Client de passage'];
    foreach ($clients as $c) {
        $optionsClients[$c['id']] = $c['nom'] . ($c['nom_entreprise'] ? ' — ' . $c['nom_entreprise'] : '');
    }
    ?>

    <form method="post" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
      <?= champCsrf() ?>
      <input type="hidden" name="lignes" id="lignes-json" value="">

      <div class="space-y-5 xl:col-span-2">
        <?= carteDebut('Ce que le client demande') ?>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4" id="tuiles">
            <?php foreach ($prestations as $p): ?>
              <button type="button" data-article="<?= e($p['id']) ?>"
                      class="rounded-xl bg-white p-3 text-left ring-1 ring-slate-200 transition-colors hover:bg-marine-50 hover:ring-marine-300">
                <span class="block text-sm font-medium text-slate-800"><?= e($p['nom']) ?></span>
                <span class="mt-1 block text-xs text-slate-500">
                  <?= e(montant($p['prix_vente'], devise())) ?> / <?= e($p['unite']) ?>
                </span>
                <span class="mt-1 block text-[11px] <?= $p['type_impression'] === 'SUPPORT' ? 'text-blue-600' : 'text-violet-600' ?>">
                  <?= $p['type_impression'] === 'SUPPORT' ? 'Support vendu tel quel' : 'Prestation' ?>
                </span>
              </button>
            <?php endforeach; ?>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Le ticket', ['sans_padding' => true]) ?>
          <?php
          // Neuf colonnes dont six se saisissent : sans largeurs imposées, le
          // navigateur les rétrécit jusqu'à ce que les champs ne montrent plus
          // que leurs flèches. On fixe donc chaque colonne (la désignation
          // prend ce qui reste) et on laisse la carte défiler si l'écran est
          // trop étroit.
          $th = 'bg-slate-50 px-2 py-2.5 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase';
          ?>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] table-fixed divide-y divide-slate-200 text-sm">
              <colgroup>
                <col><col class="w-24"><col class="w-24"><col class="w-24"><col class="w-24">
                <col class="w-24"><col class="w-24"><col class="w-24"><col class="w-10">
              </colgroup>
              <thead>
                <tr>
                  <th class="<?= $th ?>">Désignation</th>
                  <th class="<?= $th ?>">Calcul</th>
                  <th class="<?= $th ?> text-right">Largeur (m)</th>
                  <th class="<?= $th ?> text-right">Longueur (m)</th>
                  <th class="<?= $th ?> text-right">Ex.</th>
                  <th class="<?= $th ?> text-right">Quantité</th>
                  <th class="<?= $th ?> text-right">P.U.</th>
                  <th class="<?= $th ?> text-right">Total HT</th>
                  <th class="<?= $th ?>"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100" id="corps-lignes"></tbody>
            </table>
          </div>
          <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500" id="rappel-matiere">
            La matière sort du rouleau dès l'encaissement.
          </p>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5 xl:self-start">
        <?= carteDebut('Encaisser') ?>
          <div class="space-y-4">
            <?= champBloc('Client', listeDeroulante('tiers_id', $optionsClients, '')) ?>

            <dl class="space-y-2.5 border-t border-slate-100 pt-4 text-sm">
              <div class="flex justify-between"><dt class="text-slate-500">Total HT</dt><dd class="font-medium" id="t-ht">—</dd></div>
              <div class="flex items-center justify-between gap-3">
                <dt class="text-slate-500">Remise</dt>
                <dd><input type="number" step="0.01" min="0" name="remise" id="remise" value="0"
                           class="w-28 rounded-lg border-0 px-2 py-1 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500"></dd>
              </div>
              <div class="flex justify-between"><dt class="text-slate-500">TVA</dt><dd class="font-medium" id="t-tva">—</dd></div>
              <div class="flex justify-between border-t border-slate-200 pt-3 text-lg">
                <dt class="font-semibold text-slate-800">À payer</dt>
                <dd class="font-bold text-marine-700" id="t-ttc">—</dd>
              </div>
            </dl>

            <?= champBloc('Mode de règlement', listeDeroulante('mode_paiement', MODES_PAIEMENT, 'ESPECES')) ?>
            <?= champBloc('Montant reçu (' . devise() . ')',
                saisie('montant_paye', '', ['type' => 'number', 'id' => 'paye',
                    'attrs' => ['step' => '0.01', 'min' => 0]])) ?>

            <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
              Rendu monnaie : <strong id="rendu">—</strong>
            </p>

            <?= champBloc('Note', saisie('notes', '')) ?>

            <?= bouton('Encaisser et imprimer le ticket', ['classe' => 'w-full py-3', 'icone' => 'imprimante']) ?>
            <p class="text-center text-xs text-slate-500">
              Un bon de livraison est créé et le ticket 80 mm s'ouvre.
            </p>
          </div>
        <?= carteFin() ?>
      </div>
    </form>

    <script>
    (function () {
      const PRESTATIONS = <?= json_encode($prestations, JSON_UNESCAPED_UNICODE) ?>;
      const SUPPORTS = <?= json_encode($supports, JSON_UNESCAPED_UNICODE) ?>;
      const MODES = <?= json_encode(MODES_LIGNE, JSON_UNESCAPED_UNICODE) ?>;
      const DEVISE = <?= json_encode(devise()) ?>;

      let lignes = [];

      const nf = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const nq = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 3 });
      const corps = document.getElementById('corps-lignes');
      const champRemise = document.getElementById('remise');
      const champPaye = document.getElementById('paye');
      const arrondir = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

      /** Jumeau de `unitesLigne` (lib/imprimerie.php). */
      function unites(l) {
        const largeur = +l.largeur || 0, hauteur = +l.hauteur || 0, quantite = +l.quantite || 0;
        if (l.mode === 'ML') return Math.round(hauteur * quantite * 10000) / 10000;
        if (l.mode === 'PIECE') return Math.round(quantite * 10000) / 10000;
        return Math.round(largeur * hauteur * quantite * 10000) / 10000;
      }

      /** Jumeau de `consommationPrevue` (lib/imprimerie.php). */
      function consommation(l) {
        const support = SUPPORTS.find((s) => s.id === l.support_id);
        if (!support) return 0;
        const quantite = +l.quantite || 0, largeur = +l.largeur || 0, longueur = +l.hauteur || 0;
        const parUnite = +l.conso_unitaire || 0, laize = +support.laize || 0;
        const arrondi = (v) => Math.round(v * 1000) / 1000;

        if (parUnite > 0) return arrondi(parUnite * quantite);
        if (l.mode === 'PIECE') return 0;
        if (l.mode === 'ML') return arrondi(longueur * quantite);
        if (laize <= 0) return arrondi(largeur * longueur * quantite);
        return arrondi(Math.max(1, Math.ceil(Math.round((largeur / laize) * 1e6) / 1e6)) * longueur * quantite);
      }

      function totaux() {
        const detail = lignes.map((l) => {
          const u = unites(l);
          return { ...l, unites: u, total_ht: arrondir(u * (+l.prix_unitaire || 0)) };
        });
        const totalHT = arrondir(detail.reduce((s, l) => s + l.total_ht, 0));
        const remise = Math.min(arrondir(+champRemise.value || 0), totalHT);
        const baseHT = arrondir(totalHT - remise);
        const facteur = totalHT > 0 ? baseHT / totalHT : 1;
        const totalTVA = arrondir(detail.reduce((s, l) => s + l.total_ht * facteur * ((+l.tva || 0) / 100), 0));
        return { detail, totalHT, remise, totalTVA, totalTTC: arrondir(baseHT + totalTVA) };
      }

      // `saisie-dense` (outech.css) retire les flèches des champs numériques : dans
      // une colonne de six rems, elles mangeraient la moitié de la place.
      const CASE = 'saisie-dense w-full rounded border-0 bg-transparent px-2 py-1.5 ring-1 ring-inset ring-transparent hover:ring-slate-200 focus:ring-2 focus:ring-marine-500';

      function rendre() {
        corps.innerHTML = '';
        if (!lignes.length) {
          corps.innerHTML = '<tr><td colspan="9" class="px-4 py-10 text-center text-slate-400">'
            + 'Touchez une prestation pour commencer.</td></tr>';
        }

        const dimensions = ['largeur', 'hauteur', 'quantite'];
        const nombres = [...dimensions, 'prix_unitaire'];

        lignes.forEach((ligne) => {
          const tr = document.createElement('tr');
          // Les cellules suivent les largeurs du `colgroup` : padding serré, et
          // rien qui déborde — les nombres restent lisibles en entier.
          tr.innerHTML =
            '<td class="px-2 py-2"><input data-champ="designation" class="' + CASE + '"></td>'
            + '<td class="px-2 py-2"><select data-champ="mode" class="' + CASE + '">'
            + Object.keys(MODES).map((m) => '<option value="' + m + '">' + MODES[m].label + '</option>').join('')
            + '</select></td>'
            + dimensions.map((c) => '<td class="px-2 py-2"><input type="number" step="0.001" min="0" '
                + 'data-champ="' + c + '" class="' + CASE + ' text-right"></td>').join('')
            + '<td class="px-2 py-2 text-right whitespace-nowrap text-slate-500" data-role="unites">—</td>'
            + '<td class="px-2 py-2"><input type="number" step="0.01" min="0" data-champ="prix_unitaire" class="' + CASE + ' text-right"></td>'
            + '<td class="px-2 py-2 text-right font-medium whitespace-nowrap" data-role="total">—</td>'
            + '<td class="px-2 py-2 text-right"><button type="button" data-role="retirer" '
            + 'class="rounded p-1 text-rose-500 hover:bg-rose-50" aria-label="Retirer">✕</button></td>';

          tr.querySelector('[data-champ=designation]').value = ligne.designation || '';
          tr.querySelector('[data-champ=mode]').value = ligne.mode || 'M2';
          for (const c of nombres) {
            const champ = tr.querySelector('[data-champ=' + c + ']');
            if (champ) champ.value = ligne[c] ?? 0;
          }

          tr.querySelectorAll('input, select').forEach((entree) => {
            entree.addEventListener('input', () => {
              const champ = entree.dataset.champ;
              ligne[champ] = (champ === 'designation' || champ === 'mode') ? entree.value
                : (parseFloat(String(entree.value).replace(',', '.')) || 0);
              if (champ === 'mode') appliquerMode(tr, ligne);
              maj();
            });
          });

          tr.querySelector('[data-role=retirer]').addEventListener('click', () => {
            lignes = lignes.filter((l) => l.cle !== ligne.cle);
            rendre();
          });

          appliquerMode(tr, ligne);
          corps.appendChild(tr);
        });

        maj();
      }

      function appliquerMode(tr, ligne) {
        const largeur = tr.querySelector('[data-champ=largeur]');
        const hauteur = tr.querySelector('[data-champ=hauteur]');
        const mode = ligne.mode || 'M2';
        largeur.disabled = mode !== 'M2';
        hauteur.disabled = mode === 'PIECE';
        [largeur, hauteur].forEach((c) => c.classList.toggle('opacity-40', c.disabled));
      }

      function maj() {
        const t = totaux();
        document.getElementById('t-ht').textContent = nf.format(t.totalHT) + ' ' + DEVISE;
        document.getElementById('t-tva').textContent = nf.format(t.totalTVA) + ' ' + DEVISE;
        document.getElementById('t-ttc').textContent = nf.format(t.totalTTC) + ' ' + DEVISE;

        const paye = +champPaye.value || 0;
        document.getElementById('rendu').textContent = paye > t.totalTTC
          ? nf.format(paye - t.totalTTC) + ' ' + DEVISE : '—';

        // Ce que la vente va prendre aux rouleaux, dit avant d'encaisser.
        const parSupport = {};
        lignes.forEach((l) => {
          const q = consommation(l);
          if (!q) return;
          parSupport[l.support_id] = (parSupport[l.support_id] || 0) + q;
        });
        const morceaux = Object.keys(parSupport).map((id) => {
          const s = SUPPORTS.find((x) => x.id === id);
          return s ? nq.format(parSupport[id]) + ' ' + s.unite + ' de ' + s.nom : '';
        }).filter(Boolean);
        document.getElementById('rappel-matiere').textContent = morceaux.length
          ? 'Sortira du stock : ' + morceaux.join(' · ')
          : "La matière sort du rouleau dès l'encaissement.";

        corps.querySelectorAll('tr').forEach((tr, i) => {
          const calculee = t.detail[i];
          if (!calculee) return;
          const cu = tr.querySelector('[data-role=unites]');
          if (cu) cu.textContent = nq.format(calculee.unites) + ' ' + MODES[calculee.mode || 'M2'].court;
          const ct = tr.querySelector('[data-role=total]');
          if (ct) ct.textContent = nf.format(calculee.total_ht);
        });

        document.getElementById('lignes-json').value = JSON.stringify(
          t.detail.filter((l) => String(l.designation).trim() && l.unites > 0).map((l) => ({
            article_id: l.article_id || null, designation: l.designation, mode: l.mode,
            support_id: l.support_id || null, conso_prevue: null,
            largeur: +l.largeur || 0, hauteur: +l.hauteur || 0, quantite: +l.quantite || 0,
            prix_unitaire: +l.prix_unitaire || 0, remise: 0, tva: +l.tva || 0,
          })),
        );
      }

      document.getElementById('tuiles').addEventListener('click', (evenement) => {
        const bouton = evenement.target.closest('[data-article]');
        if (!bouton) return;
        const p = PRESTATIONS.find((x) => x.id === bouton.dataset.article);
        if (!p) return;

        lignes.push({
          cle: Math.random().toString(36).slice(2, 10),
          article_id: p.id, designation: p.nom,
          mode: /m[eè]tre carr/i.test(p.unite || '') ? 'M2'
              : (/m[eè]tre\b/i.test(p.unite || '') ? 'ML' : 'PIECE'),
          // Un support vendu tel quel se prélève sur lui-même.
          support_id: p.type_impression === 'SUPPORT' ? p.id : (p.support_id || ''),
          conso_unitaire: +p.conso_unitaire || 0,
          largeur: 0, hauteur: 0, quantite: 1,
          prix_unitaire: +p.prix_vente, tva: +p.tva,
        });

        rendre();
        const dernier = corps.querySelector('tr:last-child [data-champ=hauteur]:not([disabled])')
          || corps.querySelector('tr:last-child [data-champ=quantite]');
        if (dernier) dernier.focus();
      });

      champRemise.addEventListener('input', maj);
      champPaye.addEventListener('input', maj);
      rendre();
    })();
    </script>
<?php });
