<?php
/**
 * Console — liste des sociétés clientes.
 * `/console/demandes` sert la même page, filtrée sur les demandes en attente.
 */

exigerConsole();

$demandesSeules = cheminDemande() === '/console/demandes';

$q = (string) param('q', '');
$filtre = $demandesSeules ? 'DEMANDE' : (string) param('situation', '');

$args = [];
$where = ['1 = 1'];
if ($q !== '') {
    $where[] = '(nom LIKE ? OR code LIKE ? OR contact_nom LIKE ? OR contact_email LIKE ? OR ville LIKE ?)';
    for ($i = 0; $i < 5; $i++) $args[] = '%' . $q . '%';
}

$societes = tous(
    'SELECT * FROM saas_societe WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC',
    $args,
);

// Le filtre porte sur la situation calculée (essai terminé, Pro…), pas sur la
// colonne `statut` : c'est ce que voit le gestionnaire.
if ($filtre !== '') {
    $societes = array_values(array_filter($societes, fn($s) => situation($s) === $filtre));
}

$titre = $demandesSeules ? 'Demandes d\'accès' : 'Sociétés clientes';

pageConsole($titre, function () use ($societes, $q, $filtre, $demandesSeules, $titre) {
    echo enTetePage(
        $titre,
        count($societes) . ' résultat' . (count($societes) > 1 ? 's' : ''),
        boutonLien('/console/societes/nouvelle', 'Nouvelle société', ['icone' => 'plus']),
    );

    if (!placeDisponible()) {
        echo '<div class="mb-4">' . alerte(
            'Le plafond de ' . e(reglage('max_societes')) . ' sociétés est atteint. '
            . 'Relevez-le dans <a class="font-medium underline" href="' . e(u('/console/reglages'))
            . '">Réglages</a> pour en installer d\'autres.', 'attention') . '</div>';
    }
    ?>

    <form method="get" class="mb-4 flex flex-wrap items-center gap-2">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, code, contact, ville…"
             class="<?= CLASSES_SAISIE ?> max-w-xs">
      <?php if (!$demandesSeules): ?>
        <select name="situation" class="<?= CLASSES_SAISIE ?> max-w-[14rem]" onchange="this.form.submit()">
          <option value="">Toutes les situations</option>
          <?php foreach (SITUATIONS as $cle => $entree): ?>
            <option value="<?= e($cle) ?>"<?= $filtre === $cle ? ' selected' : '' ?>><?= e($entree['label']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?= bouton('Filtrer', ['variante' => 'secondaire']) ?>
    </form>

    <?= carteDebut(null, ['sans_padding' => true]) ?>
      <?= tableauDebut(['Société', 'Code', 'Situation', 'Formule', 'Échéance', 'Contact', '']) ?>
        <?php foreach ($societes as $s): $jours = joursRestants($s); ?>
          <tr>
            <td class="<?= CLASSES_TD ?>">
              <a class="font-medium text-marine-700 hover:underline" href="<?= e(u('/console/societes/' . $s['id'])) ?>"><?= e($s['nom']) ?></a>
              <div class="text-xs text-slate-500"><?= e($s['ville'] ?: '—') ?></div>
            </td>
            <td class="<?= CLASSES_TD ?> font-mono text-xs" title="<?= e($s['code']) ?>">
              <?= e(codeCourt($s['code'])) ?>
            </td>
            <td class="<?= CLASSES_TD ?>"><?= badgeSituation($s) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= e($s['formule'] === 'PRO' ? 'Pro' : 'Essai') ?></td>
            <td class="<?= CLASSES_TD ?> whitespace-nowrap">
              <?php $fin = $s['formule'] === 'ESSAI' ? $s['essai_fin'] : $s['abonnement_fin']; ?>
              <?= $fin ? e(dateFr($fin)) : '<span class="text-slate-400">illimité</span>' ?>
              <?php if ($fin && $jours !== null): ?>
                <div class="text-xs <?= $jours < 0 ? 'text-rose-600' : 'text-slate-500' ?>">
                  <?= $jours < 0 ? 'échu depuis ' . abs($jours) . ' j' : 'reste ' . $jours . ' j' ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="<?= CLASSES_TD ?>">
              <?= e($s['contact_nom'] ?: '—') ?>
              <div class="text-xs text-slate-500"><?= e($s['contact_telephone'] ?: $s['contact_email'] ?: '') ?></div>
            </td>
            <td class="<?= CLASSES_TD ?> text-right"><?= boutonLien('/console/societes/' . $s['id'], 'Ouvrir le dossier', ['variante' => 'secondaire']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$societes) echo ligneVide(7, 'Aucune société'); ?>
      <?= tableauFin() ?>
    <?= carteFin() ?>
<?php });
