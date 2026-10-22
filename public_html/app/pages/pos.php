<?php
/**
 * Point de vente tactile. Port de `src/app/pos/`.
 *
 * La caisse remet par défaut un bon de livraison : les ventes de passage sont
 * ensuite regroupées en une facture unique. La facture immédiate reste
 * disponible pour le client qui la réclame au comptoir.
 *
 * Le stock disponible borne toutes les entrées (pavé numérique, champ du
 * panier, boutons + / − et douchette) : la caisse ne vend jamais plus que ce
 * qu'il y a en rayon. Les articles non suivis en stock ne sont pas bornés.
 */

$session = exigerSession();

$erreur = null;
$ticket = null;

if (estPost()) {
    $lignes = [];
    foreach (lireLignesSaisies((string) ($_POST['lignes'] ?? '[]')) as $ligne) {
        // Le comptoir ne vend que des articles du catalogue.
        if ($ligne['article_id']) $lignes[] = $ligne;
    }

    $type = champ('type') === 'FACTURE' ? 'FACTURE' : 'BON_LIVRAISON';

    if (!$lignes) {
        $erreur = 'Le panier est vide';
    } else {
        $totaux = calculerTotaux($lignes, champNombre('remise'), 0);
        $paye = min(champNombre('montant_paye'), $totaux['total_ttc']);

        $resultat = creerDocument($session, $type, [
            'source'        => 'POS',
            'tiers_id'      => champ('tiers_id'),
            'remise'        => champNombre('remise'),
            'montant_paye'  => $paye,
            'mode_paiement' => champ('mode') ?: 'ESPECES',
        ], $lignes);

        if (isset($resultat['erreur'])) {
            $erreur = $resultat['erreur'];
        } else {
            // Même règle qu'en back-office : un chèque ou un effet encaissé au
            // comptoir doit apparaître dans le suivi, sinon il devient introuvable.
            $mode = champ('mode') ?: 'ESPECES';
            $reference = ouNull(champ('reference'));

            if ($paye > 0 && ($mode === 'CHEQUE' || $mode === 'EFFET')) {
                $paiement = un('SELECT * FROM paiement WHERE document_id = ? ORDER BY created_at DESC LIMIT 1', [$resultat['id']]);
                if ($paiement) {
                    modifier('paiement', $paiement['id'], ['reference' => $reference]);
                    inserer('effet', [
                        'type'          => $mode,
                        'sens'          => 'RECU',
                        'numero'        => $reference ?? mb_strtoupper(substr($paiement['id'], -8)),
                        'montant'       => $paye,
                        'date_emission' => maintenant(),
                        // Échéance non saisie au comptoir : à compléter dans le module.
                        'date_echeance' => maintenant(),
                        'tiers_id'      => ouNull(champ('tiers_id')),
                        'document_id'   => $resultat['id'],
                        'paiement_id'   => $paiement['id'],
                    ]);
                }
            }

            $document = un('SELECT id, numero, type FROM document WHERE id = ?', [$resultat['id']]);
            $ticket = $document;
        }
    }
}

$articles = articlesVendables($session['lieu_id']);
$clients = tous("SELECT id, nom FROM tiers WHERE actif = 1 AND type IN ('CLIENT', 'LES_DEUX') ORDER BY nom");

$categories = [];
foreach ($articles as $article) {
    if ($article['categorie']) $categories[$article['categorie']] = true;
}
$categories = array_keys($categories);
sort($categories);

$lieu = lieuCourant();

pageNue('Point de Vente', function () use ($session, $articles, $clients, $categories, $lieu, $ticket, $erreur) {
    ?>
<div class="flex h-screen flex-col bg-slate-100">
  <header class="flex shrink-0 items-center gap-3 bg-marine-900 px-4 py-2.5 text-white">
    <a href="<?= e(u(accueilPour($session['role']))) ?>" class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-sm hover:bg-white/20">
      <?= icone('fleche-g', 'size-4') ?> Retour
    </a>
    <span class="text-lg font-bold">OUTECH <span class="text-sm font-normal text-slate-300">· Point de Vente</span></span>
    <span class="ml-auto text-sm text-slate-300"><?= e($lieu['nom'] ?? '—') ?></span>
    <span class="rounded-lg bg-white/10 px-3 py-1.5 text-sm"><?= e($session['nom']) ?></span>
  </header>

  <?php if ($ticket): ?>
    <div class="flex shrink-0 flex-wrap items-center gap-2 bg-emerald-600 px-4 py-2.5 text-sm text-white">
      <?= icone('coche', 'size-4') ?>
      <span><?= e($ticket['type'] === 'FACTURE' ? 'Facture' : 'Bon de livraison') ?> <strong><?= e($ticket['numero']) ?></strong> enregistré.</span>
      <span class="ml-1 inline-flex items-center gap-1.5"><?= icone('imprimante', 'size-3.5') ?> Imprimer :</span>
      <?php foreach (['80' => '80 mm', '58' => '58 mm', 'a4' => 'A4'] as $format => $libelle): ?>
        <a href="<?= e(u('/impression/' . $ticket['id'] . '?format=' . $format)) ?>" target="_blank"
           class="rounded-lg bg-white/20 px-3 py-1 font-medium hover:bg-white/30"><?= e($libelle) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
    <!-- Catalogue -->
    <div class="flex min-h-0 flex-1 flex-col p-3">
      <div class="relative mb-3">
        <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-slate-400"><?= icone('loupe', 'size-5') ?></span>
        <input id="recherche" autofocus autocomplete="off"
               placeholder="Scanner un code-barres ou rechercher un article…"
               class="w-full rounded-xl border-0 bg-white py-3 pr-4 pl-11 text-base shadow-sm ring-1 ring-slate-200 focus:ring-2 focus:ring-marine-500">
      </div>

      <div class="mb-3 flex shrink-0 flex-wrap gap-1.5" id="categories">
        <button type="button" data-categorie="" class="rounded-lg bg-marine-600 px-3 py-1.5 text-sm font-medium text-white">Tous</button>
        <?php foreach ($categories as $categorie): ?>
          <button type="button" data-categorie="<?= e($categorie) ?>"
                  class="rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50"><?= e($categorie) ?></button>
        <?php endforeach; ?>
      </div>

      <div id="tuiles" class="grid min-h-0 flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5"></div>
    </div>

    <!-- Panier -->
    <form method="post" id="panier-form" class="flex w-full shrink-0 flex-col bg-white shadow-lg lg:w-[420px]">
      <?= champCsrf() ?>
      <input type="hidden" name="lignes" id="lignes-json" value="[]">
      <input type="hidden" name="remise" id="remise-cache" value="0">
      <input type="hidden" name="montant_paye" id="montant-paye" value="0">
      <input type="hidden" name="type" id="type-piece" value="BON_LIVRAISON">

      <div class="flex shrink-0 items-center gap-2 border-b border-slate-100 p-3">
        <span class="text-slate-400"><?= icone('panier', 'size-5') ?></span>
        <span class="font-semibold text-slate-800">Panier</span>
        <span id="compte-panier" class="rounded-full bg-marine-100 px-2 py-0.5 text-xs font-medium text-marine-700">0</span>
        <button type="button" id="vider" class="ml-auto hidden items-center gap-1 rounded-lg px-2 py-1 text-xs text-rose-600 hover:bg-rose-50">
          <?= icone('croix', 'size-3.5') ?> Vider
        </button>
      </div>

      <div class="shrink-0 space-y-2 border-b border-slate-100 p-3">
        <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1" id="choix-type">
          <button type="button" data-type="BON_LIVRAISON" class="rounded-md bg-white px-2 py-1.5 text-sm font-medium text-marine-700 shadow-sm">Bon de livraison</button>
          <button type="button" data-type="FACTURE" class="rounded-md px-2 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700">Facture</button>
        </div>

        <select name="tiers_id" class="w-full rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500">
          <option value="">Client de passage</option>
          <?php foreach ($clients as $client): ?>
            <option value="<?= e($client['id']) ?>"><?= e($client['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto" id="zone-panier"></div>

      <div class="shrink-0 space-y-3 border-t border-slate-100 p-3">
        <?php if ($erreur) echo '<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">' . e($erreur) . '</p>'; ?>

        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">Total HT</dt><dd id="p-ht">0,00</dd></div>
          <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">Remise</dt>
            <dd><input type="number" step="0.01" min="0" value="0" id="remise"
                       class="w-24 rounded-lg border-0 px-2 py-1 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500"></dd>
          </div>
          <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">TVA</dt>
            <dd class="flex items-center gap-2">
              <select id="tva" aria-label="Taux de TVA appliqué" class="rounded-lg border-0 bg-white py-1 pr-7 pl-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500">
                <option value="">Par article</option>
                <?php foreach (TAUX_TVA as $taux): ?><option value="<?= e($taux) ?>"><?= e($taux) ?> %</option><?php endforeach; ?>
              </select>
              <span class="w-16 text-right" id="p-tva">0,00</span>
            </dd>
          </div>
          <div class="flex justify-between border-t border-slate-200 pt-2 text-xl font-bold">
            <dt>Total</dt><dd class="text-marine-700" id="p-ttc">0,00 <?= e(devise()) ?></dd>
          </div>
        </dl>

        <div class="grid grid-cols-2 gap-2">
          <select name="mode" id="mode" class="rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500">
            <?php foreach (MODES_PAIEMENT as $cle => $libelle): ?><option value="<?= e($cle) ?>"><?= e($libelle) ?></option><?php endforeach; ?>
          </select>
          <input type="number" step="0.01" min="0" id="recu" placeholder="Montant reçu"
                 class="rounded-lg border-0 bg-white px-3 py-2 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500">
        </div>

        <!-- Chèque ou effet : le numéro alimente le suivi Chèques / Effets. -->
        <input name="reference" id="reference" placeholder="N° du chèque / de l'effet"
               class="hidden w-full rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500">

        <p id="rendu" class="hidden rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800"></p>

        <button type="submit" id="encaisser" disabled
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-4 text-lg font-bold text-white shadow-sm transition-colors hover:bg-emerald-700 disabled:opacity-50">
          <?= icone('coche', 'size-5') ?> <span id="libelle-encaisser">Encaisser</span>
        </button>
      </div>
    </form>
  </div>

  <!-- Pavé numérique : quantité tapée d'un coup, décimales comprises pour le vrac. -->
  <div id="pave" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-4 shadow-xl">
      <div class="mb-3">
        <p class="text-sm font-semibold text-slate-800" id="pave-nom"></p>
        <p class="text-xs text-slate-500" id="pave-detail"></p>
      </div>
      <div class="mb-3 rounded-xl bg-slate-50 px-4 py-3 text-right text-3xl font-bold text-slate-800" id="pave-valeur">1</div>
      <div class="grid grid-cols-3 gap-2" id="pave-touches">
        <?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', ',', '0', '←'] as $touche): ?>
          <button type="button" data-touche="<?= e($touche) ?>"
                  class="rounded-xl bg-slate-100 py-4 text-xl font-semibold text-slate-700 hover:bg-slate-200"><?= e($touche) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <button type="button" id="pave-annuler" class="rounded-xl bg-slate-100 py-3 font-medium text-slate-700 hover:bg-slate-200">Annuler</button>
        <button type="button" id="pave-valider" class="rounded-xl bg-marine-600 py-3 font-semibold text-white hover:bg-marine-700">Ajouter</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const ARTICLES = <?= json_encode($articles, JSON_UNESCAPED_UNICODE) ?>;
  const DEVISE = <?= json_encode(devise()) ?>;
  const nf = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const arrondir = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

  const parCle = new Map(ARTICLES.map((a) => [a.cle, a]));
  let panier = [];
  let categorie = '';
  let aQuantifier = null;
  let saisiePave = '1';
  let paveVierge = true;

  const zonePanier = document.getElementById('zone-panier');
  const tuiles = document.getElementById('tuiles');
  const recherche = document.getElementById('recherche');
  const champRemise = document.getElementById('remise');
  const champTva = document.getElementById('tva');
  const champRecu = document.getElementById('recu');
  const champMode = document.getElementById('mode');

  /**
   * Quantité vendable d'un article, Infinity s'il ne suit pas son stock.
   * Toutes les entrées du panier passent par cette borne.
   */
  const stockVendable = (cle) => {
    const a = parCle.get(cle);
    return a && a.gere_stock ? +a.stock : Infinity;
  };
  const quantiteAuPanier = (cle) => (panier.find((l) => l.cle === cle) || {}).quantite || 0;

  /** Lignes envoyées au serveur : le taux choisi au comptoir remplace celui de l'article. */
  function lignesEffectives() {
    const taux = champTva.value;
    return panier.map((l) => ({ ...l, tva: taux === '' ? l.tva : +taux }));
  }

  function totaux() {
    const lignes = lignesEffectives();
    const detail = lignes.map((l) => {
      const totalHT = arrondir(l.quantite * l.prix_unitaire * (1 - (l.remise || 0) / 100));
      return { ...l, total_ht: totalHT, total_ttc: arrondir(totalHT * (1 + (l.tva || 0) / 100)) };
    });
    const totalHT = arrondir(detail.reduce((s, l) => s + l.total_ht, 0));
    const remise = Math.min(arrondir(+champRemise.value || 0), totalHT);
    const baseHT = arrondir(totalHT - remise);
    const facteur = totalHT > 0 ? baseHT / totalHT : 1;
    const totalTVA = arrondir(detail.reduce((s, l) => s + l.total_ht * facteur * ((l.tva || 0) / 100), 0));
    return { detail, totalHT, totalTVA, totalTTC: arrondir(baseHT + totalTVA) };
  }

  function rendreCatalogue() {
    const terme = recherche.value.trim().toLowerCase();
    const visibles = ARTICLES
      .filter((a) => (categorie ? a.categorie === categorie : true))
      .filter((a) => !terme || a.nom.toLowerCase().includes(terme)
        || String(a.reference).toLowerCase().includes(terme)
        || (a.code_barre || '').toLowerCase().includes(terme))
      .slice(0, 60);

    tuiles.innerHTML = '';
    if (visibles.length === 0) {
      tuiles.innerHTML = '<p class="col-span-full py-16 text-center text-sm text-slate-400">Aucun article ne correspond.</p>';
      return;
    }

    for (const article of visibles) {
      const rupture = article.gere_stock && +article.stock <= 0;
      const bouton = document.createElement('button');
      bouton.type = 'button';
      bouton.className = 'flex flex-col overflow-hidden rounded-xl bg-white text-left shadow-sm ring-1 ring-slate-200 transition-shadow hover:shadow-md';
      bouton.innerHTML =
        '<span class="grid aspect-square w-full place-items-center overflow-hidden bg-slate-50">'
        + (article.image_url ? '<img src="' + <?= json_encode(base()) ?> + article.image_url + '" alt="" loading="lazy" class="size-full object-cover">'
                             : '<span class="text-slate-300 text-2xl">▦</span>')
        + '</span><span class="flex flex-1 flex-col justify-between gap-2 p-3">'
        + '<span class="line-clamp-2 text-sm font-medium text-slate-800"></span>'
        + '<span class="flex items-end justify-between gap-2"><span class="text-sm font-bold text-marine-700">'
        + nf.format(+article.prix_vente) + ' ' + DEVISE + '</span>'
        + '<span class="text-xs ' + (rupture ? 'font-medium text-rose-600' : 'text-slate-400') + '">'
        + (article.gere_stock ? article.stock + ' ' + article.unite : '—') + '</span></span></span>';
      bouton.querySelector('.line-clamp-2').textContent = article.nom;
      bouton.addEventListener('click', () => ouvrirPave(article));
      tuiles.appendChild(bouton);
    }
  }

  function ajouter(article, quantite) {
    const existante = panier.find((l) => l.cle === article.cle);
    const voulue = (existante ? existante.quantite : 0) + quantite;
    const retenue = Math.min(voulue, stockVendable(article.cle));
    if (retenue <= 0) return;

    if (existante) existante.quantite = retenue;
    else panier.push({
      cle: article.cle, article_id: article.article_id, variante_id: article.variante_id,
      designation: article.nom, quantite: retenue, prix_unitaire: +article.prix_vente,
      remise: 0, tva: +article.tva,
    });
    rendrePanier();
  }

  function rendrePanier() {
    const t = totaux();
    const lignes = lignesEffectives();

    document.getElementById('compte-panier').textContent = panier.length;
    document.getElementById('vider').className = (panier.length ? 'ml-auto inline-flex' : 'hidden')
      + ' items-center gap-1 rounded-lg px-2 py-1 text-xs text-rose-600 hover:bg-rose-50';

    if (panier.length === 0) {
      zonePanier.innerHTML = '<p class="py-16 text-center text-sm text-slate-400">Panier vide — sélectionnez des articles.</p>';
    } else {
      const ul = document.createElement('ul');
      ul.className = 'divide-y divide-slate-100';
      lignes.forEach((ligne, index) => {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-2 p-3';
        li.innerHTML =
          '<div class="min-w-0 flex-1"><p class="truncate text-sm font-medium text-slate-800"></p>'
          + '<p class="text-xs text-slate-500">' + nf.format(ligne.prix_unitaire) + ' ' + DEVISE + ' · TVA ' + ligne.tva + ' %</p></div>'
          + '<div class="flex items-center gap-1">'
          + '<button type="button" data-role="moins" class="grid size-7 place-items-center rounded-lg bg-slate-100 hover:bg-slate-200">−</button>'
          + '<input data-role="qte" type="number" step="0.001" min="0" class="w-16 rounded-lg border-0 px-1 py-1 text-center text-sm ring-1 ring-inset ring-slate-300">'
          + '<button type="button" data-role="plus" class="grid size-7 place-items-center rounded-lg bg-slate-100 hover:bg-slate-200">+</button></div>'
          + '<span class="w-20 text-right text-sm font-semibold whitespace-nowrap">' + nf.format(t.detail[index].total_ttc) + '</span>'
          + '<button type="button" data-role="retirer" class="rounded p-1 text-rose-500 hover:bg-rose-50" aria-label="Retirer">✕</button>';

        li.querySelector('p').textContent = ligne.designation;
        li.querySelector('[data-role=qte]').value = ligne.quantite;

        const cible = panier.find((l) => l.cle === ligne.cle);
        li.querySelector('[data-role=moins]').addEventListener('click', () => {
          cible.quantite = Math.min(cible.quantite - 1, stockVendable(cible.cle));
          panier = panier.filter((l) => l.quantite > 0);
          rendrePanier();
        });
        li.querySelector('[data-role=plus]').addEventListener('click', () => {
          cible.quantite = Math.min(cible.quantite + 1, stockVendable(cible.cle));
          rendrePanier();
        });
        li.querySelector('[data-role=qte]').addEventListener('change', (ev) => {
          const voulue = parseFloat(String(ev.target.value).replace(',', '.')) || 0;
          cible.quantite = Math.min(voulue, stockVendable(cible.cle));
          panier = panier.filter((l) => l.quantite > 0);
          rendrePanier();
        });
        li.querySelector('[data-role=retirer]').addEventListener('click', () => {
          panier = panier.filter((l) => l.cle !== ligne.cle);
          rendrePanier();
        });

        ul.appendChild(li);
      });
      zonePanier.innerHTML = '';
      zonePanier.appendChild(ul);
    }

    document.getElementById('p-ht').textContent = nf.format(t.totalHT);
    document.getElementById('p-tva').textContent = nf.format(t.totalTVA);
    document.getElementById('p-ttc').textContent = nf.format(t.totalTTC) + ' ' + DEVISE;

    document.getElementById('lignes-json').value = JSON.stringify(lignes.map((l) => ({
      article_id: l.article_id, variante_id: l.variante_id, designation: l.designation,
      quantite: l.quantite, prix_unitaire: l.prix_unitaire, remise: l.remise, tva: l.tva,
    })));
    document.getElementById('remise-cache').value = +champRemise.value || 0;
    document.getElementById('montant-paye').value = t.totalTTC;

    const encaisser = document.getElementById('encaisser');
    encaisser.disabled = t.totalTTC <= 0;
    document.getElementById('libelle-encaisser').textContent = 'Encaisser ' + nf.format(t.totalTTC) + ' ' + DEVISE;

    const recu = +champRecu.value || 0;
    const rendu = document.getElementById('rendu');
    rendu.classList.toggle('hidden', recu <= 0);
    rendu.textContent = 'Rendu monnaie : ' + nf.format(Math.max(0, recu - t.totalTTC)) + ' ' + DEVISE;
  }

  /* --------------------------------------------------------------- pavé */

  const pave = document.getElementById('pave');

  function ouvrirPave(article) {
    aQuantifier = article;
    saisiePave = '1';
    paveVierge = true;
    const reste = stockVendable(article.cle) - quantiteAuPanier(article.cle);
    document.getElementById('pave-nom').textContent = article.nom;
    document.getElementById('pave-detail').textContent =
      nf.format(+article.prix_vente) + ' ' + DEVISE
      + (article.gere_stock ? ' · reste ' + (reste === Infinity ? '∞' : reste) + ' ' + article.unite : '');
    document.getElementById('pave-valeur').textContent = saisiePave;
    pave.classList.remove('hidden');
    pave.classList.add('flex');
  }

  function fermerPave() {
    pave.classList.add('hidden');
    pave.classList.remove('flex');
    aQuantifier = null;
    recherche.focus();
  }

  document.getElementById('pave-touches').addEventListener('click', (ev) => {
    const touche = ev.target.dataset.touche;
    if (!touche) return;

    if (touche === '←') {
      saisiePave = saisiePave.slice(0, -1) || '0';
    } else if (touche === ',') {
      saisiePave = saisiePave.includes(',') ? saisiePave : saisiePave + ',';
    } else {
      // Le « 1 » proposé à l'ouverture est remplacé par le premier chiffre tapé,
      // sinon taper « 5 » donnerait « 15 ».
      saisiePave = paveVierge ? touche : saisiePave + touche;
    }
    paveVierge = false;
    document.getElementById('pave-valeur').textContent = saisiePave;
  });

  document.getElementById('pave-annuler').addEventListener('click', fermerPave);
  document.getElementById('pave-valider').addEventListener('click', () => {
    const quantite = parseFloat(saisiePave.replace(',', '.')) || 0;
    if (aQuantifier && quantite > 0) ajouter(aQuantifier, quantite);
    fermerPave();
  });

  /* ------------------------------------------------------------ contrôles */

  recherche.addEventListener('input', rendreCatalogue);

  // Scan code-barres : la douchette termine par « Entrée » et ajoute une unité.
  recherche.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    const terme = recherche.value.trim().toLowerCase();
    if (!terme) return;
    const exact = ARTICLES.find((a) => (a.code_barre || '').toLowerCase() === terme
      || String(a.reference).toLowerCase() === terme);
    const cible = exact || ARTICLES.find((a) => a.nom.toLowerCase().includes(terme));
    if (!cible) return;

    // Un bip sur un article épuisé n'ajoute rien : le pavé l'annonce.
    if (stockVendable(cible.cle) - quantiteAuPanier(cible.cle) <= 0) ouvrirPave(cible);
    else ajouter(cible, 1);
    recherche.value = '';
    rendreCatalogue();
  });

  document.getElementById('categories').addEventListener('click', (ev) => {
    const bouton = ev.target.closest('button[data-categorie]');
    if (!bouton) return;
    categorie = bouton.dataset.categorie;
    for (const b of document.querySelectorAll('#categories button')) {
      const actif = b.dataset.categorie === categorie;
      b.className = actif
        ? 'rounded-lg bg-marine-600 px-3 py-1.5 text-sm font-medium text-white'
        : 'rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50';
    }
    rendreCatalogue();
  });

  document.getElementById('choix-type').addEventListener('click', (ev) => {
    const bouton = ev.target.closest('button[data-type]');
    if (!bouton) return;
    document.getElementById('type-piece').value = bouton.dataset.type;
    for (const b of document.querySelectorAll('#choix-type button')) {
      b.className = b === bouton
        ? 'rounded-md bg-white px-2 py-1.5 text-sm font-medium text-marine-700 shadow-sm'
        : 'rounded-md px-2 py-1.5 text-sm font-medium text-slate-500 hover:text-slate-700';
    }
  });

  document.getElementById('vider').addEventListener('click', () => { panier = []; rendrePanier(); });
  champRemise.addEventListener('input', rendrePanier);
  champTva.addEventListener('change', rendrePanier);
  champRecu.addEventListener('input', rendrePanier);
  champMode.addEventListener('change', () => {
    document.getElementById('reference').classList.toggle('hidden', !['CHEQUE', 'EFFET'].includes(champMode.value));
  });

  rendreCatalogue();
  rendrePanier();
})();
</script>
<?php }, 'h-full');
