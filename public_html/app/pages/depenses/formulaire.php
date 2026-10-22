<?php
/** Saisie d'une dépense (création et modification). */

$session = exigerSession();

$id = $params['id'] ?? null;
$depense = null;

if ($id) {
    $depense = un('SELECT * FROM depense WHERE id = ?', [$id]);
    if (!$depense) introuvable('Dépense introuvable');

    // Une consommation de stock tient ses montants de ses lignes : la
    // retoucher ici désaccorderait la dépense et le stock. Elle se lit sur sa
    // fiche, et se corrige en la reprenant puis en la redéclarant.
    if (($depense['origine'] ?? 'SAISIE') === 'CONSOMMATION') {
        rediriger('/depenses/' . $depense['id'], "Une consommation de stock ne se modifie pas à la main.", 'erreur');
    }
}

$erreur = null;
$v = $depense ?? [
    'date' => dateInput('now'), 'designation' => '', 'categorie_id' => '',
    'montant_ht' => '0', 'tva' => '0', 'montant_paye' => '0', 'mode' => 'ESPECES',
    'beneficiaire' => '', 'recurrente' => 0, 'note' => '',
];

if (estPost()) {
    $designation = (string) champ('designation', '');
    $montantHT = max(0, champNombre('montant_ht'));
    $tva = min(100, max(0, champNombre('tva')));
    $montantTTC = round($montantHT * (1 + $tva / 100), 2);
    $paye = min(max(0, champNombre('montant_paye')), $montantTTC);

    $donnees = [
        'date'            => horodatage(champ('date')) ?? maintenant(),
        'designation'     => $designation,
        'categorie_id'    => ouNull(champ('categorie_id')),
        'montant_ht'      => $montantHT,
        'tva'             => $tva,
        'montant_ttc'     => $montantTTC,
        'montant_paye'    => $paye,
        'statut_paiement' => statutDePaiement($paye, $montantTTC),
        'mode'            => champ('mode') ?: 'ESPECES',
        'beneficiaire'    => ouNull(champ('beneficiaire')),
        'recurrente'      => champBool('recurrente') ? 1 : 0,
        'note'            => ouNull(champ('note')),
    ];
    $v = array_merge($v, $donnees, ['date' => (string) champ('date')]);

    if (mb_strlen($designation) < 2) {
        $erreur = 'La désignation est obligatoire';
    } else {
        if ($depense) {
            modifier('depense', $depense['id'], $donnees);
            rediriger('/depenses', 'Dépense enregistrée.');
        }
        inserer('depense', $donnees + [
            'numero'  => numeroSuivant('DP'),
            'lieu_id' => $session['lieu_id'],
            'user_id' => $session['user_id'],
        ]);
        rediriger('/depenses', 'Dépense créée.');
    }
}

$categories = tous('SELECT id, nom FROM categorie_depense ORDER BY nom');

page($depense ? 'Modifier la dépense' : 'Nouvelle dépense', function () use ($depense, $v, $erreur, $categories) {
    echo enTetePage(
        $depense ? 'Modifier « ' . $depense['designation'] . ' »' : 'Nouvelle dépense',
        $depense ? $depense['numero'] : 'Achat de fonctionnement, charge, frais…',
        boutonLien('/depenses', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    $optionsCategories = ['' => '— Aucune —'];
    foreach ($categories as $c) $optionsCategories[$c['id']] = $c['nom'];

    $optionsTva = [];
    foreach (TAUX_TVA as $taux) $optionsTva[$taux] = $taux . ' %';
    ?>
    <div class="max-w-3xl">
      <?= carteDebut('Informations') ?>
        <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <?= champCsrf() ?>
          <?php if ($erreur) echo '<div class="sm:col-span-2">' . alerte(e($erreur), 'erreur') . '</div>'; ?>
          <?= champBloc('Date', saisie('date', dateInput($v['date']), ['type' => 'date'])) ?>
          <?= champBloc('Catégorie', listeDeroulante('categorie_id', $optionsCategories, $v['categorie_id'])) ?>
          <?= champBloc('Désignation', saisie('designation', $v['designation'], ['attrs' => ['required' => true]]), ['obligatoire' => true, 'classe' => 'sm:col-span-2']) ?>
          <?= champBloc('Montant HT (' . devise() . ')', saisie('montant_ht', num($v['montant_ht']), ['type' => 'number', 'id' => 'montant-ht', 'attrs' => ['step' => '0.01', 'min' => 0]])) ?>
          <?= champBloc('TVA (%)', listeDeroulante('tva', $optionsTva, (string) (int) num($v['tva']), ['id' => 'taux-tva'])) ?>
          <?= champBloc('Montant payé (' . devise() . ')', saisie('montant_paye', num($v['montant_paye']), ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0]]), ['aide' => 'Laisser 0 pour une dépense impayée']) ?>
          <?= champBloc('Mode de règlement', listeDeroulante('mode', MODES_PAIEMENT, $v['mode'])) ?>
          <?= champBloc('Bénéficiaire', saisie('beneficiaire', $v['beneficiaire'])) ?>
          <div class="flex items-end"><?= caseACocher('recurrente', (bool) $v['recurrente'], 'Dépense récurrente') ?></div>
          <?= champBloc('Note', zoneTexte('note', $v['note'], ['lignes' => 3]), ['classe' => 'sm:col-span-2']) ?>

          <p class="sm:col-span-2 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Montant TTC : <strong id="apercu-ttc">—</strong>
          </p>

          <div class="sm:col-span-2 flex justify-end"><?= bouton($depense ? 'Enregistrer' : 'Créer la dépense') ?></div>
        </form>

        <script>
          // Le TTC affiché suit la saisie ; le serveur le recalcule à l'écriture.
          const ht = document.getElementById('montant-ht');
          const taux = document.getElementById('taux-tva');
          const apercu = document.getElementById('apercu-ttc');
          const nf = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          const maj = () => {
            const ttc = (+ht.value || 0) * (1 + (+taux.value || 0) / 100);
            apercu.textContent = nf.format(Math.round(ttc * 100) / 100) + ' ' + <?= json_encode(devise()) ?>;
          };
          ht.addEventListener('input', maj);
          taux.addEventListener('change', maj);
          maj();
        </script>
      <?= carteFin() ?>
    </div>
<?php });
