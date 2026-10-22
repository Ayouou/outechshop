<?php
/**
 * Saisie d'un document (création et modification).
 * Port de `documents/FormulaireDocument.tsx` + `pages.tsx`.
 *
 * Les lignes sont éditées côté navigateur et postées en JSON dans le champ
 * `lignes` : les totaux affichés pendant la saisie sont recalculés par le
 * serveur avant écriture, la page n'a donc pas le dernier mot sur les montants.
 */

$session = exigerSession();

$sens = str_starts_with(cheminDemande(), '/ventes') ? 'VENTE' : 'ACHAT';
$type = typeDepuisChemin($sens, $params['chemin'] ?? '');
if (!$type) introuvable('Type de document inconnu');

$meta = meta($type);
$base = urlListe($type);
$id = $params['id'] ?? null;
$document = null;
$lignesInitiales = [];

if ($id) {
    $document = un('SELECT * FROM document WHERE id = ?', [$id]);
    if (!$document || $document['type'] !== $type) introuvable('Document introuvable');

    foreach (tous('SELECT * FROM ligne_document WHERE document_id = ? ORDER BY ordre', [$id]) as $ligne) {
        $lignesInitiales[] = [
            'article_id'    => $ligne['article_id'],
            'variante_id'   => $ligne['variante_id'],
            'designation'   => $ligne['designation'],
            'quantite'      => num($ligne['quantite']),
            'prix_unitaire' => num($ligne['prix_unitaire']),
            'remise'        => num($ligne['remise']),
            'tva'           => num($ligne['tva']),
        ];
    }
}

$erreur = null;

if (estPost()) {
    $lignes = lireLignesSaisies((string) ($_POST['lignes'] ?? '[]'));
    $entete = [
        'tiers_id'      => champ('tiers_id'),
        'date'          => champ('date'),
        'date_echeance' => champ('date_echeance'),
        'reference'     => champ('reference'),
        'notes'         => champ('notes'),
        'remise'        => champNombre('remise'),
        'frais_port'    => champNombre('frais_port'),
        'montant_paye'  => champNombre('montant_paye'),
        'mode_paiement' => champ('mode_paiement') ?: 'ESPECES',
    ];

    $resultat = $document
        ? modifierDocument($session, $document['id'], $entete, $lignes)
        : creerDocument($session, $type, $entete, $lignes);

    if (isset($resultat['erreur'])) {
        $erreur = $resultat['erreur'];
        // La saisie est renvoyée telle quelle au formulaire.
        $lignesInitiales = $lignes;
    } else {
        rediriger(urlDocument($type, $resultat['id']), $document ? 'Document enregistré.' : 'Document créé.');
    }
}

// Référentiels communs aux formulaires de documents.
$articles = articlesVendables($session['lieu_id']);
$tiers = tous(
    'SELECT id, nom, code, nom_entreprise FROM tiers
      WHERE actif = 1 AND type IN (?, \'LES_DEUX\') ORDER BY nom',
    [$sens === 'VENTE' ? 'CLIENT' : 'FOURNISSEUR'],
);

// Les tarifs de groupe ne s'appliquent qu'à la vente.
$tarifs = $sens === 'VENTE' ? tarifsParGroupe() : ['prix_par_groupe' => [], 'groupe_par_tiers' => []];

page($document ? 'Modifier le document' : $meta['titre_nouveau'], function () use (
    $document, $meta, $type, $sens, $base, $articles, $tiers, $tarifs, $lignesInitiales, $erreur
) {
    echo enTetePage(
        $document ? "Modifier {$meta['avec_article']} {$document['numero']}" : $meta['titre_nouveau'],
        $document ? 'Les mouvements de stock seront recalculés' : 'Renseignez les lignes puis enregistrez',
        boutonLien($document ? urlDocument($type, $document['id']) : $base, 'Retour',
            ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    // Un paiement immédiat n'est proposé qu'à la création d'une pièce payable.
    $payable = $meta['payable'] && !$document;

    $optionsTiers = ['' => $sens === 'VENTE' ? 'Client de passage' : '— Sélectionner —'];
    foreach ($tiers as $t) {
        $optionsTiers[$t['id']] = $t['nom'] . ($t['nom_entreprise'] ? ' — ' . $t['nom_entreprise'] : '');
    }
    ?>

    <form method="post" class="space-y-5" id="formulaire-document">
      <?= champCsrf() ?>
      <input type="hidden" name="lignes" id="lignes-json" value="">

      <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

      <?= carteDebut('Informations générales') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <?= champBloc($sens === 'VENTE' ? 'Client' : 'Fournisseur',
              listeDeroulante('tiers_id', $optionsTiers, $document['tiers_id'] ?? champ('tiers_id', ''), ['id' => 'tiers'])) ?>
          <?= champBloc('Date', saisie('date', dateInput($document['date'] ?? 'now'), ['type' => 'date'])) ?>
          <?= champBloc("Date d'échéance", saisie('date_echeance', dateInput($document['date_echeance'] ?? null), ['type' => 'date'])) ?>
          <?= champBloc('Référence externe', saisie('reference', $document['reference'] ?? '', ['attrs' => ['placeholder' => 'BC client…']])) ?>
        </div>
      <?= carteFin() ?>

      <?php if (estRetour($type)): ?>
        <?= carteDebut($sens === 'ACHAT' ? 'À retourner au fournisseur' : 'À reprendre au client', [
            'sous_titre' => 'Reprend les documents du tiers ; cliquez sur « Retourner » pour ajouter la ligne',
        ]) ?>
          <div class="mb-3 flex flex-wrap items-center gap-2">
            <label class="text-sm text-slate-500">Période</label>
            <select id="periode" class="<?= CLASSES_SAISIE_AUTO ?>">
              <option value="30">30 jours</option>
              <option value="90">90 jours</option>
              <option value="180">6 mois</option>
              <option value="365">1 an</option>
            </select>
          </div>
          <div id="historique" class="text-sm text-slate-500">
            Sélectionnez d'abord <?= $sens === 'ACHAT' ? 'le fournisseur' : 'le client' ?> pour voir ce qui peut être retourné.
          </div>
        <?= carteFin() ?>
      <?php endif; ?>

      <?= carteDebut('Articles', ['sans_padding' => true]) ?>
        <div class="relative border-b border-slate-100 p-4">
          <input id="recherche" autocomplete="off"
                 placeholder="Rechercher un article par nom, référence ou code-barres…"
                 class="<?= CLASSES_SAISIE ?>">
          <ul id="suggestions" class="absolute z-20 mt-1 hidden max-h-72 w-[calc(100%-2rem)] overflow-y-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-200"></ul>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                <th class="px-3 py-2.5 text-left">Désignation</th>
                <th class="w-24 px-3 py-2.5 text-right">Qté</th>
                <th class="w-32 px-3 py-2.5 text-right">P.U. HT</th>
                <th class="w-24 px-3 py-2.5 text-right">Remise %</th>
                <th class="w-24 px-3 py-2.5 text-right">TVA %</th>
                <th class="w-32 px-3 py-2.5 text-right">Total HT</th>
                <th class="w-12 px-3 py-2.5"></th>
              </tr>
            </thead>
            <tbody id="corps-lignes" class="divide-y divide-slate-100"></tbody>
          </table>
        </div>

        <div class="border-t border-slate-100 p-4">
          <button type="button" id="ligne-libre" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-marine-600 hover:bg-marine-50">
            <?= icone('plus', 'size-4') ?> Ligne libre
          </button>
        </div>
      <?= carteFin() ?>

      <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
          <?= carteDebut('Notes') ?>
            <?= zoneTexte('notes', $document['notes'] ?? '', ['lignes' => 5, 'attrs' => ['placeholder' => 'Conditions particulières, mentions…']]) ?>
            <?php if ($payable): ?>
              <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <?= champBloc('Montant payé à la création', saisie('montant_paye', '0', ['type' => 'number', 'id' => 'montant-paye', 'attrs' => ['step' => '0.01', 'min' => 0]])) ?>
                <?= champBloc('Mode de paiement', listeDeroulante('mode_paiement', MODES_PAIEMENT, 'ESPECES')) ?>
              </div>
            <?php endif; ?>
          <?= carteFin() ?>
        </div>

        <?= carteDebut('Totaux') ?>
          <dl class="space-y-2.5 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Total HT</dt><dd class="font-medium" id="t-ht">—</dd></div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-slate-500">Remise globale</dt>
              <dd><input type="number" step="0.01" min="0" name="remise" id="remise"
                         value="<?= e($document ? num($document['remise']) : 0) ?>"
                         class="w-28 rounded-lg border-0 px-2 py-1 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500"></dd>
            </div>
            <div class="flex justify-between"><dt class="text-slate-500">Base HT</dt><dd class="font-medium" id="t-base">—</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">TVA</dt><dd class="font-medium" id="t-tva">—</dd></div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-slate-500">Frais de port</dt>
              <dd><input type="number" step="0.01" min="0" name="frais_port" id="frais-port"
                         value="<?= e($document ? num($document['frais_port']) : 0) ?>"
                         class="w-28 rounded-lg border-0 px-2 py-1 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500"></dd>
            </div>
            <div class="flex justify-between border-t border-slate-200 pt-3 text-base">
              <dt class="font-semibold text-slate-800">Total TTC</dt><dd class="font-bold text-marine-700" id="t-ttc">—</dd>
            </div>
            <div class="flex justify-between text-sm hidden" id="bloc-reste">
              <dt class="text-slate-500">Reste à payer</dt><dd class="font-medium text-rose-600" id="t-reste">—</dd>
            </div>
          </dl>
        <?= carteFin() ?>
      </div>

      <div class="flex justify-end">
        <button type="submit" class="rounded-lg bg-marine-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-marine-700">
          <?= e($document ? 'Enregistrer les modifications' : $meta['libelle_creer']) ?>
        </button>
      </div>
    </form>

    <script>
    (function () {
      const ARTICLES = <?= json_encode($articles, JSON_UNESCAPED_UNICODE) ?>;
      const PRIX_GROUPE = <?= json_encode($tarifs['prix_par_groupe'], JSON_UNESCAPED_UNICODE) ?>;
      const GROUPE_TIERS = <?= json_encode($tarifs['groupe_par_tiers'], JSON_UNESCAPED_UNICODE) ?>;
      const SENS = <?= json_encode($sens) ?>;
      const TYPE = <?= json_encode($type) ?>;
      const DEVISE = <?= json_encode(devise()) ?>;
      const EST_RETOUR = <?= json_encode(estRetour($type)) ?>;

      let lignes = <?= json_encode($lignesInitiales, JSON_UNESCAPED_UNICODE) ?>;
      lignes = lignes.map((l) => ({ ...l, cle: Math.random().toString(36).slice(2, 10) }));

      const nf = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const corps = document.getElementById('corps-lignes');
      const champRemise = document.getElementById('remise');
      const champFrais = document.getElementById('frais-port');
      const champPaye = document.getElementById('montant-paye');
      const selectTiers = document.getElementById('tiers');

      const arrondir = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

      /** Même calcul que `calculerTotaux` côté serveur (lib/calculs.php). */
      function totaux() {
        const detail = lignes.map((l) => {
          const brut = (+l.quantite || 0) * (+l.prix_unitaire || 0);
          const totalHT = arrondir(brut * (1 - (+l.remise || 0) / 100));
          return { ...l, total_ht: totalHT };
        });
        const totalHT = arrondir(detail.reduce((s, l) => s + l.total_ht, 0));
        const remise = Math.min(arrondir(+champRemise.value || 0), totalHT);
        const baseHT = arrondir(totalHT - remise);
        const facteur = totalHT > 0 ? baseHT / totalHT : 1;
        const totalTVA = arrondir(detail.reduce((s, l) => s + l.total_ht * facteur * ((+l.tva || 0) / 100), 0));
        const fraisPort = +champFrais.value || 0;
        return { detail, totalHT, remise, baseHT, totalTVA, totalTTC: arrondir(baseHT + totalTVA + fraisPort) };
      }

      /** Prix applicable : tarif du groupe du client si défini, sinon prix de base. */
      function prixApplicable(article, client) {
        if (SENS === 'ACHAT') return +article.prix_achat;
        const groupe = GROUPE_TIERS[client];
        const tarif = groupe ? (PRIX_GROUPE[groupe] || {})[article.article_id] : undefined;
        return tarif !== undefined ? tarif : +article.prix_vente;
      }

      function rendre() {
        const t = totaux();
        corps.innerHTML = '';

        if (lignes.length === 0) {
          corps.innerHTML = '<tr><td colspan="7" class="px-4 py-10 text-center text-slate-400">Aucune ligne — recherchez un article ci-dessus.</td></tr>';
        }

        lignes.forEach((ligne, index) => {
          const tr = document.createElement('tr');
          const champs = ['quantite', 'prix_unitaire', 'remise', 'tva'];
          tr.innerHTML =
            '<td class="px-3 py-2"><input data-champ="designation" class="w-full rounded border-0 bg-transparent px-2 py-1.5 ring-1 ring-inset ring-transparent hover:ring-slate-200 focus:ring-2 focus:ring-marine-500"></td>'
            + champs.map((c) =>
                '<td class="px-3 py-2"><input type="number" step="' + (c === 'quantite' ? '0.001' : '0.01')
                + '" min="0" data-champ="' + c + '" class="w-full rounded border-0 bg-transparent px-2 py-1.5 text-right ring-1 ring-inset ring-transparent hover:ring-slate-200 focus:ring-2 focus:ring-marine-500"></td>').join('')
            + '<td class="px-3 py-2 text-right font-medium whitespace-nowrap">' + nf.format(t.detail[index].total_ht) + '</td>'
            + '<td class="px-3 py-2 text-right"><button type="button" data-role="retirer" class="rounded p-1 text-rose-500 hover:bg-rose-50" aria-label="Supprimer la ligne">✕</button></td>';

          tr.querySelector('[data-champ=designation]').value = ligne.designation;
          for (const c of champs) tr.querySelector('[data-champ=' + c + ']').value = ligne[c];

          tr.querySelectorAll('input').forEach((input) => {
            input.addEventListener('input', () => {
              const champ = input.dataset.champ;
              ligne[champ] = champ === 'designation' ? input.value : (parseFloat(String(input.value).replace(',', '.')) || 0);
              majTotaux();
            });
          });

          tr.querySelector('[data-role=retirer]').addEventListener('click', () => {
            lignes = lignes.filter((l) => l.cle !== ligne.cle);
            rendre();
            if (EST_RETOUR) chargerHistorique();
          });

          corps.appendChild(tr);
        });

        majTotaux();
      }

      function majTotaux() {
        const t = totaux();
        document.getElementById('t-ht').textContent = nf.format(t.totalHT) + ' ' + DEVISE;
        document.getElementById('t-base').textContent = nf.format(t.baseHT) + ' ' + DEVISE;
        document.getElementById('t-tva').textContent = nf.format(t.totalTVA) + ' ' + DEVISE;
        document.getElementById('t-ttc').textContent = nf.format(t.totalTTC) + ' ' + DEVISE;

        // Les totaux de ligne affichés suivent la saisie sans re-rendre la table.
        corps.querySelectorAll('tr').forEach((tr, i) => {
          if (!t.detail[i]) return;
          tr.children[5].textContent = nf.format(t.detail[i].total_ht);
        });

        if (champPaye) {
          const paye = +champPaye.value || 0;
          document.getElementById('bloc-reste').classList.toggle('hidden', paye <= 0);
          document.getElementById('t-reste').textContent = nf.format(Math.max(0, t.totalTTC - paye)) + ' ' + DEVISE;
        }

        document.getElementById('lignes-json').value = JSON.stringify(
          lignes.filter((l) => String(l.designation).trim() && +l.quantite > 0).map((l) => ({
            article_id: l.article_id, variante_id: l.variante_id, designation: l.designation,
            quantite: +l.quantite, prix_unitaire: +l.prix_unitaire, remise: +l.remise, tva: +l.tva,
          })),
        );
      }

      function ajouter(source, quantite) {
        const existante = lignes.find((l) => l.article_id === source.article_id && l.variante_id === source.variante_id);
        if (existante) existante.quantite = +existante.quantite + quantite;
        else lignes.push({ cle: Math.random().toString(36).slice(2, 10), ...source, quantite });
        rendre();
      }

      /* ------------------------------------------------------ recherche article */

      const recherche = document.getElementById('recherche');
      const suggestions = document.getElementById('suggestions');

      recherche.addEventListener('input', () => {
        const terme = recherche.value.trim().toLowerCase();
        suggestions.innerHTML = '';
        suggestions.classList.toggle('hidden', terme === '');
        if (!terme) return;

        const trouves = ARTICLES.filter((a) =>
          a.nom.toLowerCase().includes(terme)
          || String(a.reference).toLowerCase().includes(terme)
          || (a.code_barre || '').toLowerCase().includes(terme)).slice(0, 8);

        for (const article of trouves) {
          const li = document.createElement('li');
          const prix = prixApplicable(article, selectTiers ? selectTiers.value : '');
          li.innerHTML = '<button type="button" class="flex w-full items-center justify-between gap-4 px-4 py-2 text-left text-sm hover:bg-slate-50">'
            + '<span><span class="font-medium text-slate-800"></span><span class="ml-2 text-xs text-slate-400"></span></span>'
            + '<span class="text-right whitespace-nowrap text-slate-600">' + nf.format(prix) + ' ' + DEVISE
            + (article.gere_stock ? '<span class="block text-[11px] ' + (article.stock > 0 ? 'text-slate-400' : 'text-rose-500') + '">'
                + (article.stock > 0 ? article.stock + ' ' + article.unite : 'rupture') + '</span>' : '')
            + '</span></button>';
          li.querySelector('.font-medium').textContent = article.nom;
          li.querySelector('.text-xs').textContent = article.reference;

          li.querySelector('button').addEventListener('click', () => {
            ajouter({
              article_id: article.article_id, variante_id: article.variante_id,
              designation: article.nom, prix_unitaire: prix, remise: 0, tva: +article.tva,
            }, 1);
            recherche.value = '';
            suggestions.classList.add('hidden');
          });
          suggestions.appendChild(li);
        }
      });

      document.addEventListener('click', (ev) => {
        if (!ev.target.closest('#suggestions') && ev.target !== recherche) suggestions.classList.add('hidden');
      });

      document.getElementById('ligne-libre').addEventListener('click', () => {
        lignes.push({ cle: Math.random().toString(36).slice(2, 10), article_id: null, variante_id: null,
          designation: '', quantite: 1, prix_unitaire: 0, remise: 0, tva: 20 });
        rendre();
      });

      champRemise.addEventListener('input', majTotaux);
      champFrais.addEventListener('input', majTotaux);
      if (champPaye) champPaye.addEventListener('input', majTotaux);

      /* -------------------------------------------- changement de client (tarifs) */

      if (selectTiers) {
        selectTiers.addEventListener('change', () => {
          // Changer le client repositionne les prix des lignes article.
          for (const ligne of lignes) {
            if (!ligne.article_id) continue;
            const article = ARTICLES.find((a) => a.article_id === ligne.article_id && a.variante_id === ligne.variante_id);
            if (article) ligne.prix_unitaire = prixApplicable(article, selectTiers.value);
          }
          rendre();
          if (EST_RETOUR) chargerHistorique();
        });
      }

      /* ---------------------------------------------------- historique retournable */

      const zoneHistorique = document.getElementById('historique');
      const periode = document.getElementById('periode');

      function quantitesPosees() {
        const cumul = {};
        for (const l of lignes) {
          if (!l.article_id) continue;
          const cle = l.article_id + ':' + (l.variante_id || '');
          cumul[cle] = (cumul[cle] || 0) + (+l.quantite || 0);
        }
        return cumul;
      }

      async function chargerHistorique() {
        if (!zoneHistorique) return;
        const tiersId = selectTiers ? selectTiers.value : '';
        if (!tiersId) {
          zoneHistorique.innerHTML = '<div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-200">Sélectionnez d\'abord le tiers pour voir ce qui peut être retourné.</div>';
          return;
        }

        zoneHistorique.textContent = 'Chargement…';
        try {
          const r = await fetch(<?= json_encode(u('/api/retours')) ?> + '?type=' + TYPE + '&tiers_id=' + encodeURIComponent(tiersId) + '&jours=' + periode.value);
          if (!r.ok) throw new Error(r.status);
          const donnees = await r.json();
          const posees = quantitesPosees();

          if (!donnees.lignes || donnees.lignes.length === 0) {
            zoneHistorique.innerHTML = '<p class="py-6 text-center text-sm text-slate-400">Rien à retourner sur la période.</p>';
            return;
          }

          const table = document.createElement('table');
          table.className = 'min-w-full divide-y divide-slate-200 text-sm';
          table.innerHTML = '<thead><tr>'
            + ['Article', 'Documents', 'P.U.', 'Livré', 'Déjà retourné', 'Restant', ''].map((h, i) =>
                '<th class="bg-slate-50 px-3 py-2 text-xs font-semibold uppercase text-slate-500 ' + (i > 1 && i < 6 ? 'text-right' : 'text-left') + '">' + h + '</th>').join('')
            + '</tr></thead><tbody class="divide-y divide-slate-100"></tbody>';

          const tbody = table.querySelector('tbody');
          for (const source of donnees.lignes) {
            const deja = posees[source.cle] || 0;
            const restant = Math.max(0, source.quantite_restante - deja);
            const tr = document.createElement('tr');
            tr.innerHTML = '<td class="px-3 py-2"></td>'
              + '<td class="px-3 py-2 text-xs text-slate-400"></td>'
              + '<td class="px-3 py-2 text-right">' + nf.format(source.prix_unitaire) + '</td>'
              + '<td class="px-3 py-2 text-right">' + source.quantite_origine + '</td>'
              + '<td class="px-3 py-2 text-right">' + source.quantite_retournee + '</td>'
              + '<td class="px-3 py-2 text-right font-medium">' + restant + '</td>'
              + '<td class="px-3 py-2 text-right"></td>';
            tr.children[0].textContent = source.designation;
            tr.children[1].textContent = source.documents.join(', ');

            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'rounded-lg bg-marine-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-marine-700 disabled:opacity-40';
            bouton.textContent = 'Retourner';
            bouton.disabled = restant <= 0;
            bouton.addEventListener('click', () => {
              // Le retour reprend le prix facturé à l'origine, pas le tarif courant.
              ajouter({
                article_id: source.article_id, variante_id: source.variante_id,
                designation: source.designation, prix_unitaire: source.prix_unitaire,
                remise: 0, tva: source.tva,
              }, restant);
              chargerHistorique();
            });
            tr.children[6].appendChild(bouton);
            tbody.appendChild(tr);
          }

          zoneHistorique.innerHTML = '<div class="overflow-x-auto"></div>';
          zoneHistorique.firstChild.appendChild(table);
        } catch (e) {
          zoneHistorique.innerHTML = '<div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-inset ring-rose-200">Historique indisponible.</div>';
        }
      }

      if (periode) periode.addEventListener('change', chargerHistorique);
      if (EST_RETOUR) chargerHistorique();

      rendre();
    })();
    </script>
<?php });
