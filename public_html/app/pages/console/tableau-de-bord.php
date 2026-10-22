<?php
/** Console — vue d'ensemble de la plateforme. */

exigerConsole();

$societes = tous('SELECT * FROM saas_societe ORDER BY created_at DESC');

$compteurs = ['DEMANDE' => 0, 'ESSAI' => 0, 'PRO' => 0, 'ESSAI_EXPIRE' => 0,
              'PRO_EXPIRE' => 0, 'SUSPENDUE' => 0, 'REFUSEE' => 0, 'SANS_BASE' => 0];

$demandes = [];
$bientot = [];   // essais qui s'achèvent dans les 3 jours
$echus = [];

foreach ($societes as $societe) {
    $situation = situation($societe);
    $compteurs[$situation]++;

    if ($situation === 'DEMANDE') $demandes[] = $societe;
    if ($situation === 'ESSAI' && (int) joursRestants($societe) <= 3) $bientot[] = $societe;
    if (in_array($situation, ['ESSAI_EXPIRE', 'PRO_EXPIRE'], true)) $echus[] = $societe;
}

$installees = $compteurs['ESSAI'] + $compteurs['PRO'] + $compteurs['ESSAI_EXPIRE']
    + $compteurs['PRO_EXPIRE'] + $compteurs['SUSPENDUE'] + $compteurs['SANS_BASE'];

$journal = tous('SELECT * FROM saas_journal ORDER BY created_at DESC LIMIT 8');

pageConsole('Tableau de bord', function () use ($compteurs, $demandes, $bientot, $echus, $journal, $installees) {
    echo enTetePage(
        'Tableau de bord',
        $installees . ' société' . ($installees > 1 ? 's' : '') . ' sur ' . reglage('max_societes')
            . ' · essai de ' . dureeEssai() . ' jours',
        boutonLien('/console/societes/nouvelle', 'Nouvelle société', ['icone' => 'plus']),
    );
    ?>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
      <?= tuile('Demandes en attente', (string) $compteurs['DEMANDE'], [
          'icone' => 'flux', 'couleur' => $compteurs['DEMANDE'] ? 'text-violet-600' : 'text-slate-900']) ?>
      <?= tuile('Essais en cours', (string) $compteurs['ESSAI'], ['icone' => 'horloge']) ?>
      <?= tuile('Abonnés Pro', (string) $compteurs['PRO'], ['icone' => 'coche', 'couleur' => 'text-emerald-600']) ?>
      <?= tuile('Essais terminés', (string) ($compteurs['ESSAI_EXPIRE'] + $compteurs['PRO_EXPIRE']), [
          'icone' => 'alerte', 'couleur' => 'text-rose-600']) ?>
      <?= tuile('Suspendues', (string) $compteurs['SUSPENDUE'], ['icone' => 'baisse']) ?>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
      <?= carteDebut('Demandes à traiter', [
          'sans_padding' => true,
          'actions' => $demandes ? boutonLien('/console/demandes', 'Tout voir', ['variante' => 'secondaire']) : '',
      ]) ?>
        <?= tableauDebut(['Société', 'Contact', 'Reçue le', '']) ?>
          <?php foreach (array_slice($demandes, 0, 5) as $d): ?>
            <tr>
              <td class="<?= CLASSES_TD ?>">
                <a class="font-medium text-marine-700 hover:underline" href="<?= e(u('/console/societes/' . $d['id'])) ?>"><?= e($d['nom']) ?></a>
                <div class="text-xs text-slate-500"><?= e($d['ville'] ?: '—') ?></div>
              </td>
              <td class="<?= CLASSES_TD ?>">
                <?= e($d['contact_nom'] ?: '—') ?>
                <div class="text-xs text-slate-500"><?= e($d['contact_telephone'] ?: $d['contact_email'] ?: '') ?></div>
              </td>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($d['created_at'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right">
                <?= boutonLien('/console/societes/' . $d['id'], 'Examiner', ['variante' => 'secondaire']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$demandes) echo ligneVide(4, 'Aucune demande en attente'); ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <?= carteDebut('Essais à relancer', ['sans_padding' => true,
          'sous_titre' => 'Essais qui s\'achèvent sous 3 jours, et abonnements échus']) ?>
        <?= tableauDebut(['Société', 'Situation', 'Fin', '']) ?>
          <?php foreach (array_merge($bientot, $echus) as $s): ?>
            <tr>
              <td class="<?= CLASSES_TD ?>">
                <a class="font-medium text-marine-700 hover:underline" href="<?= e(u('/console/societes/' . $s['id'])) ?>"><?= e($s['nom']) ?></a>
                <div class="text-xs text-slate-500"><?= e($s['code']) ?></div>
              </td>
              <td class="<?= CLASSES_TD ?>"><?= badgeSituation($s) ?></td>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap">
                <?= e(dateFr($s['formule'] === 'ESSAI' ? $s['essai_fin'] : $s['abonnement_fin'])) ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right">
                <?= boutonLien('/console/societes/' . $s['id'], 'Traiter', ['variante' => 'secondaire']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$bientot && !$echus) echo ligneVide(4, 'Rien à relancer'); ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>

    <div class="mt-5">
      <?= carteDebut('Dernières actions', ['sans_padding' => true,
          'actions' => boutonLien('/console/journal', 'Journal complet', ['variante' => 'secondaire'])]) ?>
        <?= tableauDebut(['Quand', 'Action', 'Détail', 'Par']) ?>
          <?php foreach ($journal as $ligne): ?>
            <tr>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e(dateHeureFr($ligne['created_at'])) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= badge($ligne['action']) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($ligne['detail'] ?: '—') ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($ligne['acteur'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$journal) echo ligneVide(4, 'Aucune action enregistrée'); ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
