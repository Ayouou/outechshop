<?php
/**
 * Saisie d'un ordre de travail (création et modification).
 *
 * Comme pour les documents commerciaux, les lignes sont éditées côté
 * navigateur et postées en JSON : le serveur recalcule surfaces et totaux
 * avant d'écrire, la page n'a jamais le dernier mot sur les montants.
 */

// L'atelier produit les dossiers, il ne les ouvre pas et n'en fixe pas le prix.
$session = exigerRole(...ROLES_IMPRIMERIE_GESTION);

$id = $params['id'] ?? null;
$travail = null;
$lignesInitiales = [];

if ($id) {
    $travail = un('SELECT * FROM travail WHERE id = ?', [$id]);
    if (!$travail) introuvable('Ordre de travail introuvable');

    foreach (tous('SELECT * FROM travail_ligne WHERE travail_id = ? ORDER BY ordre', [$id]) as $ligne) {
        $lignesInitiales[] = [
            'article_id'    => $ligne['article_id'],
            'support_id'    => $ligne['support_id'],
            'conso_prevue'  => num($ligne['conso_prevue']),
            'designation'   => $ligne['designation'],
            'mode'          => $ligne['mode'],
            'largeur'       => num($ligne['largeur']),
            'hauteur'       => num($ligne['hauteur']),
            'quantite'      => num($ligne['quantite']),
            'prix_unitaire' => num($ligne['prix_unitaire']),
            'remise'        => num($ligne['remise']),
            'tva'           => num($ligne['tva']),
        ];
    }
}

$erreur = null;
$v = $travail ?? [
    'date' => dateInput('now'), 'date_promise' => '', 'designation' => '', 'description' => '',
    'priorite' => 'NORMALE', 'tiers_id' => '', 'contact' => '', 'machine_id' => '',
    'designer_id' => '', 'machiniste_id' => '', 'remise' => '0', 'avance' => '0',
    'notes' => '', 'image_url' => null,
];

if (estPost()) {
    $lignes = lireLignesTravail((string) ($_POST['lignes'] ?? '[]'));

    $entete = [
        'date'          => champ('date'),
        'date_promise'  => champ('date_promise'),
        'designation'   => champ('designation'),
        'description'   => champ('description'),
        'priorite'      => champ('priorite'),
        'tiers_id'      => champ('tiers_id'),
        'contact'       => champ('contact'),
        'machine_id'    => champ('machine_id'),
        'designer_id'   => champ('designer_id'),
        'machiniste_id' => champ('machiniste_id'),
        'remise'        => champNombre('remise'),
        'avance'        => champNombre('avance'),
        'notes'         => champ('notes'),
    ];

    try {
        $imageUrl = appliquerImage($travail['image_url'] ?? null, (string) champ('designation'));
    } catch (RuntimeException $e) {
        $imageUrl = false;
        $erreur = $e->getMessage();
    }

    // `false` quand l'aperçu n'a pas bougé : la colonne n'est pas touchée.
    $entete['image_url'] = $imageUrl === false ? ($travail['image_url'] ?? null) : $imageUrl;

    $v = array_merge($v, $entete, ['date' => (string) champ('date')]);

    if (!$erreur) {
        $resultat = enregistrerTravail($session, $id, $entete, $lignes);

        if (isset($resultat['erreur'])) {
            $erreur = $resultat['erreur'];
            $lignesInitiales = $lignes;
        } else {
            rediriger('/imprimerie/travaux/' . $resultat['id'],
                $travail ? 'Travail enregistré.' : 'Ordre de travail créé.');
        }
    }
}

$clients = tous(
    "SELECT id, nom, nom_entreprise FROM tiers
      WHERE actif = 1 AND type IN ('CLIENT', 'LES_DEUX') ORDER BY nom",
);
$machines = tous('SELECT id, nom, laize FROM machine WHERE actif = 1 ORDER BY nom');
$utilisateurs = tous('SELECT id, nom FROM utilisateur WHERE actif = 1 ORDER BY nom');

// Prestations proposées à la saisie : tout article actif, avec son prix et sa
// TVA. Les rouleaux suivis en stock y figurent aussi — utiles pour la revente
// au mètre (DTF, vinyle) — mais leur matière sort par l'écran « consommation ».
$prestations = tous(
    "SELECT id, nom, unite, prix_vente, tva, gere_stock, support_id, conso_unitaire,
            type_impression
       FROM article WHERE actif = 1
      ORDER BY type_impression = 'PRESTATION' DESC, nom",
);

// Les rouleaux : ce que les lignes peuvent prélever, avec leur laize et ce
// qu'il en reste aujourd'hui.
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

page($travail ? 'Modifier le travail' : 'Nouveau travail', function () use (
    $travail, $v, $erreur, $clients, $machines, $utilisateurs, $prestations, $supports, $lignesInitiales
) {
    echo enTetePage(
        $travail ? 'Modifier ' . $travail['numero'] : 'Nouvel ordre de travail',
        $travail ? $travail['designation'] : "Ce que le client commande, et ce qu'il faut produire",
        boutonLien($travail ? '/imprimerie/travaux/' . $travail['id'] : '/imprimerie/travaux', 'Retour',
            ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    $optionsClients = ['' => 'Client de passage'];
    foreach ($clients as $c) {
        $optionsClients[$c['id']] = $c['nom'] . ($c['nom_entreprise'] ? ' — ' . $c['nom_entreprise'] : '');
    }

    $optionsMachines = ['' => '— À définir —'];
    foreach ($machines as $m) {
        $optionsMachines[$m['id']] = $m['nom'] . (num($m['laize']) > 0 ? ' (' . nombre($m['laize']) . ' m)' : '');
    }

    $optionsUtilisateurs = ['' => '— Non affecté —'];
    foreach ($utilisateurs as $u) $optionsUtilisateurs[$u['id']] = $u['nom'];

    $optionsPriorite = [];
    foreach (PRIORITES_TRAVAIL as $cle => $p) $optionsPriorite[$cle] = $p['label'];
    ?>

    <form method="post" class="space-y-5">
      <?= champCsrf() ?>
      <input type="hidden" name="lignes" id="lignes-json" value="">

      <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

      <?= carteDebut('Le dossier') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <?= champBloc('Client', listeDeroulante('tiers_id', $optionsClients, $v['tiers_id'])) ?>
          <?= champBloc('Contact / téléphone', saisie('contact', $v['contact'])) ?>
          <?= champBloc('Date de la commande', saisie('date', dateInput($v['date']), ['type' => 'date'])) ?>
          <?= champBloc('Promise pour le', saisie('date_promise', dateInput($v['date_promise']), ['type' => 'date']),
              ['aide' => 'Le tableau signale les retards']) ?>
          <?= champBloc('Désignation', saisie('designation', $v['designation'],
              ['attrs' => ['required' => true, 'placeholder' => 'Bâche 3×2 m — Café Al Amal']]),
              ['obligatoire' => true, 'classe' => 'sm:col-span-2']) ?>
          <?= champBloc('Priorité', listeDeroulante('priorite', $optionsPriorite, $v['priorite'])) ?>
          <?= champBloc('Machine prévue', listeDeroulante('machine_id', $optionsMachines, $v['machine_id'])) ?>
          <?= champBloc('Designer', listeDeroulante('designer_id', $optionsUtilisateurs, $v['designer_id'])) ?>
          <?= champBloc('Machiniste', listeDeroulante('machiniste_id', $optionsUtilisateurs, $v['machiniste_id'])) ?>
          <?= champBloc('Consignes de production', zoneTexte('description', $v['description'], ['lignes' => 2]),
              ['classe' => 'sm:col-span-2 lg:col-span-4', 'aide' => 'Support, finition, œillets, sens de lecture…']) ?>
        </div>
      <?= carteFin() ?>

      <?= carteDebut('À produire', ['sans_padding' => true]) ?>
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-5 py-4">
          <label class="min-w-64 flex-1">
            <span class="mb-1.5 block text-sm font-medium text-slate-700">Reprendre une prestation du catalogue</span>
            <select id="prestation" class="<?= CLASSES_SAISIE ?>">
              <option value="">— Saisie libre —</option>
              <?php foreach ($prestations as $p): ?>
                <option value="<?= e($p['id']) ?>"><?= e($p['nom']) ?> — <?= e(montant($p['prix_vente'], devise())) ?> / <?= e($p['unite']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="button" id="ajouter-ligne" class="<?= classesBouton('secondaire') ?>">
            <?= icone('plus', 'size-4') ?> Ajouter la ligne
          </button>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead>
              <tr>
                <th class="<?= CLASSES_TH ?>">Désignation</th>
                <th class="<?= CLASSES_TH ?>">Support</th>
                <th class="<?= CLASSES_TH ?>">Calcul</th>
                <th class="<?= CLASSES_TH ?> text-right">Largeur (m)</th>
                <th class="<?= CLASSES_TH ?> text-right">Longueur (m)</th>
                <th class="<?= CLASSES_TH ?> text-right">Ex.</th>
                <th class="<?= CLASSES_TH ?> text-right">Quantité</th>
                <th class="<?= CLASSES_TH ?> text-right">À sortir</th>
                <th class="<?= CLASSES_TH ?> text-right">P.U.</th>
                <th class="<?= CLASSES_TH ?> text-right">Rem. %</th>
                <th class="<?= CLASSES_TH ?> text-right">TVA %</th>
                <th class="<?= CLASSES_TH ?> text-right">Total HT</th>
                <th class="<?= CLASSES_TH ?>"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100" id="corps-lignes"></tbody>
          </table>
        </div>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
          « À sortir » est ce que le rouleau perdra : calculé depuis les dimensions et la laize
          (un tirage plus large que la laize se découpe en lés), corrigible à la main. La matière
          quitte le stock au passage du dossier en <strong>impression</strong>.
        </p>
      <?= carteFin() ?>

      <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
          <?= carteDebut('Aperçu / bon à tirer') ?>
            <?= champImage($v['image_url'] ?? null, 'image', [
                'label' => 'Aperçu du visuel',
                'aide'  => "Le BAT que le client a validé. Il s'imprime sur la fiche d'atelier.",
            ]) ?>
            <div class="mt-4">
              <?= champBloc('Notes internes', zoneTexte('notes', $v['notes'], ['lignes' => 2])) ?>
            </div>
          <?= carteFin() ?>
        </div>

        <?= carteDebut('Totaux') ?>
          <dl class="space-y-2.5 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Total HT</dt><dd class="font-medium" id="t-ht">—</dd></div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-slate-500">Remise globale</dt>
              <dd><input type="number" step="0.01" min="0" name="remise" id="remise"
                         value="<?= e(num($v['remise'])) ?>"
                         class="w-28 rounded-lg border-0 px-2 py-1 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500"></dd>
            </div>
            <div class="flex justify-between"><dt class="text-slate-500">Base HT</dt><dd class="font-medium" id="t-base">—</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">TVA</dt><dd class="font-medium" id="t-tva">—</dd></div>
            <div class="flex justify-between border-t border-slate-200 pt-3 text-base">
              <dt class="font-semibold text-slate-800">Total TTC</dt><dd class="font-bold text-marine-700" id="t-ttc">—</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-slate-500">Avance reçue</dt>
              <dd><input type="number" step="0.01" min="0" name="avance" id="avance"
                         value="<?= e(num($v['avance'])) ?>"
                         class="w-28 rounded-lg border-0 px-2 py-1 text-right text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marine-500"></dd>
            </div>
            <div class="flex justify-between"><dt class="text-slate-500">Reste dû</dt><dd class="font-medium text-rose-600" id="t-reste">—</dd></div>
          </dl>
          <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
            L'avance est reportée sur la pièce commerciale tirée du travail.
          </p>
        <?= carteFin() ?>
      </div>

      <div class="flex justify-end">
        <?= bouton($travail ? 'Enregistrer les modifications' : 'Créer l\'ordre de travail', ['classe' => 'px-5 py-2.5']) ?>
      </div>
    </form>

    <script>
    (function () {
      const PRESTATIONS = <?= json_encode($prestations, JSON_UNESCAPED_UNICODE) ?>;
      const SUPPORTS = <?= json_encode($supports, JSON_UNESCAPED_UNICODE) ?>;
      const MODES = <?= json_encode(MODES_LIGNE, JSON_UNESCAPED_UNICODE) ?>;
      const DEVISE = <?= json_encode(devise()) ?>;
      const TVA_DEFAUT = <?= json_encode(num(societe()['tva_defaut'])) ?>;

      let lignes = <?= json_encode($lignesInitiales, JSON_UNESCAPED_UNICODE) ?>;
      lignes = lignes.map((l) => ({ ...l, cle: Math.random().toString(36).slice(2, 10) }));

      const nf = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const nq = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 3 });
      const corps = document.getElementById('corps-lignes');
      const champRemise = document.getElementById('remise');
      const champAvance = document.getElementById('avance');

      const arrondir = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

      /** Même règle que `unitesLigne` côté serveur (lib/imprimerie.php). */
      function unites(l) {
        const largeur = +l.largeur || 0, hauteur = +l.hauteur || 0, quantite = +l.quantite || 0;
        if (l.mode === 'ML') return Math.round(hauteur * quantite * 10000) / 10000;
        if (l.mode === 'PIECE') return Math.round(quantite * 10000) / 10000;
        return Math.round(largeur * hauteur * quantite * 10000) / 10000;
      }

      /** Même règle que `consommationPrevue` côté serveur (lib/imprimerie.php). */
      function consommation(l) {
        const support = SUPPORTS.find((s) => s.id === l.support_id);
        if (!support) return 0;

        const quantite = +l.quantite || 0, largeur = +l.largeur || 0, longueur = +l.hauteur || 0;
        const parUnite = +l.conso_unitaire || 0;
        const laize = +support.laize || 0;
        const arrondi = (v) => Math.round(v * 1000) / 1000;

        if (parUnite > 0) return arrondi(parUnite * quantite);
        if (l.mode === 'PIECE') return 0;
        if (l.mode === 'ML') return arrondi(longueur * quantite);
        if (laize <= 0) return arrondi(largeur * longueur * quantite);

        const les = Math.max(1, Math.ceil(Math.round((largeur / laize) * 1e6) / 1e6));
        return arrondi(les * longueur * quantite);
      }

      /** Même calcul que `calculerTotaux` (lib/calculs.php), sur les unités. */
      function totaux() {
        const detail = lignes.map((l) => {
          const u = unites(l);
          const brut = u * (+l.prix_unitaire || 0);
          return { ...l, unites: u, total_ht: arrondir(brut * (1 - (+l.remise || 0) / 100)) };
        });
        const totalHT = arrondir(detail.reduce((s, l) => s + l.total_ht, 0));
        const remise = Math.min(arrondir(+champRemise.value || 0), totalHT);
        const baseHT = arrondir(totalHT - remise);
        const facteur = totalHT > 0 ? baseHT / totalHT : 1;
        const totalTVA = arrondir(detail.reduce((s, l) => s + l.total_ht * facteur * ((+l.tva || 0) / 100), 0));
        return { detail, totalHT, remise, baseHT, totalTVA, totalTTC: arrondir(baseHT + totalTVA) };
      }

      const CLASSE_CASE = 'w-full rounded border-0 bg-transparent px-2 py-1.5 ring-1 ring-inset ring-transparent hover:ring-slate-200 focus:ring-2 focus:ring-marine-500';

      function rendre() {
        corps.innerHTML = '';

        if (lignes.length === 0) {
          corps.innerHTML = '<tr><td colspan="13" class="px-4 py-10 text-center text-slate-400">'
            + 'Aucune ligne — ajoutez ce qu\'il y a à produire.</td></tr>';
        }

        // Les cellules sont posées dans l'ordre des colonnes du tableau : rien
        // n'est inséré après coup, la lecture du code suit ce qu'on voit.
        const nombres = ['largeur', 'hauteur', 'quantite', 'prix_unitaire', 'remise', 'tva'];
        const caseNombre = (c) =>
          '<td class="px-3 py-2"><input type="number" step="' + (c === 'prix_unitaire' ? '0.01' : '0.001')
          + '" min="0" data-champ="' + c + '" class="' + CLASSE_CASE + ' text-right"></td>';

        lignes.forEach((ligne) => {
          const tr = document.createElement('tr');

          tr.innerHTML =
            '<td class="px-3 py-2 min-w-56"><input data-champ="designation" class="' + CLASSE_CASE + '"></td>'
            + '<td class="px-3 py-2 min-w-40"><select data-champ="support_id" class="' + CLASSE_CASE + '">'
            + '<option value="">— Aucun —</option>'
            + SUPPORTS.map((s) => '<option value="' + s.id + '">' + s.nom + '</option>').join('')
            + '</select></td>'
            + '<td class="px-3 py-2"><select data-champ="mode" class="' + CLASSE_CASE + '">'
            + Object.keys(MODES).map((m) => '<option value="' + m + '">' + MODES[m].label + '</option>').join('')
            + '</select></td>'
            + caseNombre('largeur') + caseNombre('hauteur') + caseNombre('quantite')
            + '<td class="px-3 py-2 text-right whitespace-nowrap text-slate-500" data-role="unites">—</td>'
            + '<td class="px-3 py-2"><input type="number" step="0.001" min="0" data-champ="conso_prevue" '
            + 'class="' + CLASSE_CASE + ' text-right" title="Ce que le rouleau perdra"></td>'
            + caseNombre('prix_unitaire') + caseNombre('remise') + caseNombre('tva')
            + '<td class="px-3 py-2 text-right font-medium whitespace-nowrap" data-role="total">—</td>'
            + '<td class="px-3 py-2 text-right"><button type="button" data-role="retirer" '
            + 'class="rounded p-1 text-rose-500 hover:bg-rose-50" aria-label="Supprimer la ligne">✕</button></td>';

          tr.querySelector('[data-champ=designation]').value = ligne.designation || '';
          tr.querySelector('[data-champ=mode]').value = ligne.mode || 'M2';
          tr.querySelector('[data-champ=support_id]').value = ligne.support_id || '';
          for (const c of nombres) tr.querySelector('[data-champ=' + c + ']').value = ligne[c] ?? 0;

          tr.querySelectorAll('input, select').forEach((entree) => {
            entree.addEventListener('input', () => {
              const champ = entree.dataset.champ;
              const texte = champ === 'designation' || champ === 'mode' || champ === 'support_id';
              ligne[champ] = texte ? entree.value
                : (parseFloat(String(entree.value).replace(',', '.')) || 0);

              // Corriger la quantité à sortir la fige : le calcul ne la
              // reprendra plus, c'est l'atelier qui sait.
              if (champ === 'conso_prevue') ligne.conso_manuelle = true;
              if (champ === 'mode') appliquerMode(tr, ligne);
              majTotaux();
            });
          });

          tr.querySelector('[data-role=retirer]').addEventListener('click', () => {
            lignes = lignes.filter((l) => l.cle !== ligne.cle);
            rendre();
          });

          appliquerMode(tr, ligne);
          corps.appendChild(tr);
        });

        majTotaux();
      }

      /** Au mètre linéaire ou à la pièce, les dimensions inutiles sont grisées. */
      function appliquerMode(tr, ligne) {
        const largeur = tr.querySelector('[data-champ=largeur]');
        const hauteur = tr.querySelector('[data-champ=hauteur]');
        const mode = ligne.mode || 'M2';
        largeur.disabled = mode !== 'M2';
        hauteur.disabled = mode === 'PIECE';
        [largeur, hauteur].forEach((c) => c.classList.toggle('opacity-40', c.disabled));
      }

      function majTotaux() {
        const t = totaux();

        document.getElementById('t-ht').textContent = nf.format(t.totalHT) + ' ' + DEVISE;
        document.getElementById('t-base').textContent = nf.format(t.baseHT) + ' ' + DEVISE;
        document.getElementById('t-tva').textContent = nf.format(t.totalTVA) + ' ' + DEVISE;
        document.getElementById('t-ttc').textContent = nf.format(t.totalTTC) + ' ' + DEVISE;
        document.getElementById('t-reste').textContent =
          nf.format(Math.max(0, t.totalTTC - (+champAvance.value || 0))) + ' ' + DEVISE;

        corps.querySelectorAll('tr').forEach((tr, i) => {
          const ligne = lignes[i];
          const calculee = t.detail[i];
          if (!ligne || !calculee) return;

          const cellUnites = tr.querySelector('[data-role=unites]');
          if (cellUnites) cellUnites.textContent = nq.format(calculee.unites) + ' ' + MODES[ligne.mode || 'M2'].court;
          const cellTotal = tr.querySelector('[data-role=total]');
          if (cellTotal) cellTotal.textContent = nf.format(calculee.total_ht);

          // Tant que l'atelier n'a rien corrigé, la quantité à sortir suit les
          // dimensions ; le champ est réécrit sans voler le curseur.
          const champConso = tr.querySelector('[data-champ=conso_prevue]');
          if (champConso && !ligne.conso_manuelle) {
            ligne.conso_prevue = consommation(ligne);
            if (document.activeElement !== champConso) champConso.value = ligne.conso_prevue || 0;
          }
        });

        document.getElementById('lignes-json').value = JSON.stringify(
          t.detail.filter((l) => String(l.designation).trim() && l.unites > 0).map((l) => ({
            article_id: l.article_id || null, designation: l.designation, mode: l.mode,
            support_id: l.support_id || null,
            // `null` laisse le serveur recalculer ; un nombre est une correction
            // assumée par l'atelier.
            conso_prevue: l.conso_manuelle ? (+l.conso_prevue || 0) : null,
            largeur: +l.largeur || 0, hauteur: +l.hauteur || 0, quantite: +l.quantite || 0,
            prix_unitaire: +l.prix_unitaire || 0, remise: +l.remise || 0, tva: +l.tva || 0,
          })),
        );
      }

      document.getElementById('ajouter-ligne').addEventListener('click', () => {
        const choix = document.getElementById('prestation');
        const prestation = PRESTATIONS.find((p) => p.id === choix.value);

        lignes.push({
          cle: Math.random().toString(36).slice(2, 10),
          article_id: prestation ? prestation.id : null,
          designation: prestation ? prestation.nom : '',
          // Une prestation vendue au mètre part au mètre linéaire ; le reste au m².
          mode: prestation && /m[eè]tre/i.test(prestation.unite || '') ? 'ML' : 'M2',
          // Le support et le facteur viennent du catalogue : c'est là qu'on a
          // dit une fois pour toutes ce que mange chaque prestation.
          support_id: prestation ? (prestation.support_id || '') : '',
          conso_unitaire: prestation ? +prestation.conso_unitaire || 0 : 0,
          conso_prevue: 0, conso_manuelle: false,
          largeur: 0, hauteur: 0, quantite: 1,
          prix_unitaire: prestation ? +prestation.prix_vente : 0,
          remise: 0, tva: prestation ? +prestation.tva : TVA_DEFAUT,
        });

        choix.value = '';
        rendre();
        const dernier = corps.querySelector('tr:last-child [data-champ=designation]');
        if (dernier) dernier.focus();
      });

      champRemise.addEventListener('input', majTotaux);
      champAvance.addEventListener('input', majTotaux);

      // Lignes rechargées : on leur rend le facteur de leur prestation, et on
      // reconnaît une quantité déjà corrigée à la main — elle ne doit pas être
      // écrasée par le recalcul au premier rendu.
      lignes.forEach((l) => {
        const prestation = PRESTATIONS.find((p) => p.id === l.article_id);
        l.conso_unitaire = prestation ? +prestation.conso_unitaire || 0 : 0;
        l.conso_manuelle = Math.abs((+l.conso_prevue || 0) - consommation(l)) > 0.0005;
      });

      if (lignes.length === 0) document.getElementById('ajouter-ligne').click();
      else rendre();
    })();
    </script>
<?php });
