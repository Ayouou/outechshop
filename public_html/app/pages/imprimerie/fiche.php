<?php
/**
 * Fiche d'atelier : le papier qui suit le dossier de poste en poste.
 *
 * Elle n'a rien d'une pièce comptable — pas de totaux au premier plan, mais
 * les dimensions, le support, la machine et les cases à cocher dont le
 * machiniste a besoin. Le navigateur se charge du PDF.
 */

$session = exigerRole(...ROLES_IMPRIMERIE);

$travail = un(
    'SELECT t.*, ti.nom AS client, ti.telephone AS client_telephone,
            l.nom AS lieu_nom, m.nom AS machine, m.laize,
            ua.nom AS accueil_nom, ud.nom AS designer_nom, um.nom AS machiniste_nom
       FROM travail t
       JOIN lieu_affaire l ON l.id = t.lieu_id
       LEFT JOIN tiers ti ON ti.id = t.tiers_id
       LEFT JOIN machine m ON m.id = t.machine_id
       LEFT JOIN utilisateur ua ON ua.id = t.user_id
       LEFT JOIN utilisateur ud ON ud.id = t.designer_id
       LEFT JOIN utilisateur um ON um.id = t.machiniste_id
      WHERE t.id = ?',
    [$params['id']],
);
if (!$travail) introuvable('Ordre de travail introuvable');

$lignes = tous('SELECT * FROM travail_ligne WHERE travail_id = ? ORDER BY ordre', [$travail['id']]);

$consommations = tous(
    'SELECT c.*, a.nom AS article_nom, a.unite FROM consommation c
       JOIN article a ON a.id = c.article_id
      WHERE c.travail_id = ? ORDER BY c.date',
    [$travail['id']],
);

$societe = societe();
$bilan = bilanMatiere($travail['id']);

// La fiche part à l'atelier : le pied de page chiffré n'est imprimé que pour
// qui a le droit de lire un montant.
$montants = voitMontants($session['role']);

pageNue("Fiche d'atelier " . $travail['numero'], function () use (
    $travail, $lignes, $consommations, $societe, $bilan, $montants
) { ?>
<div class="min-h-screen bg-slate-100 py-8">
  <style>
    @media print { @page { size: 210mm 297mm; margin: 12mm; } }
  </style>

  <div class="sans-impression mx-auto mb-6 flex max-w-[210mm] flex-wrap items-center gap-2 px-4">
    <a href="<?= e(u('/imprimerie/travaux/' . $travail['id'])) ?>" class="<?= classesBouton('secondaire') ?>">
      <?= icone('fleche-g', 'size-4') ?> Retour
    </a>
    <button type="button" onclick="window.print()" class="<?= classesBouton('primaire', 'ml-auto') ?>">
      <?= icone('imprimante', 'size-4') ?> Imprimer / PDF
    </button>
  </div>

  <div class="zone-impression mx-auto max-w-[210mm] bg-white p-10 shadow-lg">
    <header class="flex items-start justify-between gap-8 border-b-2 border-marine-600 pb-5">
      <div>
        <p class="text-xl font-bold text-marine-800"><?= e($societe['nom'] ?? 'OUTECH') ?></p>
        <p class="text-xs text-slate-600"><?= e($travail['lieu_nom']) ?><?= $societe['telephone'] ? ' · ' . e($societe['telephone']) : '' ?></p>
      </div>
      <div class="text-right">
        <p class="text-lg font-bold tracking-wide text-slate-800 uppercase">Fiche d'atelier</p>
        <p class="mt-1 font-mono text-lg text-marine-700"><?= e($travail['numero']) ?></p>
        <?php if ($travail['priorite'] === 'URGENTE'): ?>
          <p class="mt-1 inline-block rounded bg-rose-600 px-2 py-0.5 text-xs font-bold text-white uppercase">Urgent</p>
        <?php endif; ?>
      </div>
    </header>

    <section class="mt-5 grid grid-cols-2 gap-4 text-sm">
      <div class="rounded-lg bg-slate-50 p-4">
        <p class="mb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Client</p>
        <p class="font-semibold text-slate-900"><?= e($travail['client'] ?? 'Client de passage') ?></p>
        <?php $contact = $travail['contact'] ?: $travail['client_telephone']; ?>
        <?php if ($contact): ?><p class="text-slate-700"><?= e($contact) ?></p><?php endif; ?>
      </div>
      <div class="rounded-lg bg-slate-50 p-4 text-slate-700">
        <p class="mb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Dates</p>
        <p>Reçu le <strong><?= e(dateFr($travail['date'])) ?></strong><?= $travail['accueil_nom'] ? ' par ' . e($travail['accueil_nom']) : '' ?></p>
        <p>Promis le <strong><?= e($travail['date_promise'] ? dateFr($travail['date_promise']) : '—') ?></strong></p>
      </div>
    </section>

    <h1 class="mt-5 text-lg font-semibold text-slate-900"><?= e($travail['designation']) ?></h1>

    <table class="mt-4 w-full text-sm">
      <thead>
        <tr class="bg-marine-800 text-white">
          <th class="px-3 py-2 text-left font-medium">À produire</th>
          <th class="w-28 px-3 py-2 text-right font-medium">Largeur</th>
          <th class="w-28 px-3 py-2 text-right font-medium">Longueur</th>
          <th class="w-16 px-3 py-2 text-right font-medium">Ex.</th>
          <th class="w-28 px-3 py-2 text-right font-medium">Quantité</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lignes as $i => $ligne): ?>
          <tr class="<?= $i % 2 ? 'bg-slate-50' : '' ?>">
            <td class="px-3 py-2 font-medium text-slate-800"><?= e($ligne['designation']) ?></td>
            <td class="px-3 py-2 text-right"><?= $ligne['mode'] === 'M2' ? e(nombre($ligne['largeur']) . ' m') : '—' ?></td>
            <td class="px-3 py-2 text-right"><?= $ligne['mode'] === 'PIECE' ? '—' : e(nombre($ligne['hauteur']) . ' m') ?></td>
            <td class="px-3 py-2 text-right"><?= e(nombre($ligne['quantite'])) ?></td>
            <td class="px-3 py-2 text-right font-semibold"><?= e(nombre($ligne['unites']) . ' ' . uniteLigne($ligne['mode'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lignes): ?>
          <tr><td colspan="5" class="px-3 py-6 text-center text-slate-400">Aucune ligne</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <section class="mt-5 grid grid-cols-3 gap-4 text-sm">
      <div class="rounded-lg ring-1 ring-slate-200 p-3">
        <p class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Machine</p>
        <p class="text-slate-800"><?= e($travail['machine'] ?? 'À définir') ?>
          <?= num($travail['laize']) > 0 ? '<span class="text-slate-500">(laize ' . e(nombre($travail['laize'])) . ' m)</span>' : '' ?></p>
      </div>
      <div class="rounded-lg ring-1 ring-slate-200 p-3">
        <p class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Designer</p>
        <p class="text-slate-800"><?= e($travail['designer_nom'] ?? '—') ?></p>
      </div>
      <div class="rounded-lg ring-1 ring-slate-200 p-3">
        <p class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Machiniste</p>
        <p class="text-slate-800"><?= e($travail['machiniste_nom'] ?? '—') ?></p>
      </div>
    </section>

    <?php if ($travail['description']): ?>
      <section class="mt-4 rounded-lg bg-amber-50 p-4 text-sm ring-1 ring-amber-200">
        <p class="mb-1 text-[11px] font-semibold tracking-wide text-amber-700 uppercase">Consignes de production</p>
        <p class="whitespace-pre-line text-slate-800"><?= e($travail['description']) ?></p>
      </section>
    <?php endif; ?>

    <?php if ($travail['image_url']): ?>
      <section class="mt-4">
        <p class="mb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Aperçu validé</p>
        <img src="<?= e(urlImage($travail['image_url'])) ?>" alt="" class="max-h-56 rounded-lg ring-1 ring-slate-200">
      </section>
    <?php endif; ?>

    <section class="mt-5">
      <p class="mb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Matière consommée</p>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-300 text-left text-xs text-slate-500 uppercase">
            <th class="py-1.5 font-medium">Support</th>
            <th class="py-1.5 text-right font-medium">Utilisée</th>
            <th class="py-1.5 text-right font-medium">Chute</th>
            <th class="py-1.5 font-medium">Motif</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($consommations as $conso): ?>
            <tr class="border-b border-slate-100">
              <td class="py-1.5"><?= e($conso['article_nom']) ?></td>
              <td class="py-1.5 text-right"><?= e(nombre($conso['quantite']) . ' ' . $conso['unite']) ?></td>
              <td class="py-1.5 text-right"><?= e(nombre($conso['chute'])) ?></td>
              <td class="py-1.5 text-slate-500"><?= e(MOTIFS_CHUTE[$conso['motif']] ?? $conso['motif']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$consommations): ?>
            <!-- Rien n'est encore sorti : des lignes vides pour la saisie à la main. -->
            <?php for ($i = 0; $i < 3; $i++): ?>
              <tr class="border-b border-slate-100"><td class="py-3">&nbsp;</td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
          <?php endif; ?>
        </tbody>
        <?php if ($consommations): ?>
          <tfoot>
            <tr class="font-semibold">
              <td class="py-1.5">Total · taux de chute <?= e(pourcent(tauxChute($bilan['utilisee'], $bilan['chute']))) ?></td>
              <td class="py-1.5 text-right"><?= e(nombre($bilan['utilisee'])) ?></td>
              <td class="py-1.5 text-right"><?= e(nombre($bilan['chute'])) ?></td>
              <td></td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </section>

    <section class="mt-6 grid grid-cols-4 gap-3 text-xs">
      <?php foreach (['Design', 'Impression', 'Finition', 'Livraison'] as $poste): ?>
        <div class="rounded-lg ring-1 ring-slate-300 p-3">
          <p class="font-semibold text-slate-600"><?= e($poste) ?></p>
          <p class="mt-6 border-t border-dashed border-slate-300 pt-1 text-slate-400">Visa / date</p>
        </div>
      <?php endforeach; ?>
    </section>

    <footer class="mt-6 flex items-end justify-between border-t border-slate-200 pt-4 text-xs text-slate-500">
      <?php if ($montants): ?>
        <p>Total TTC : <strong class="text-slate-800"><?= e(montant($travail['total_ttc'], devise())) ?></strong>
           · Avance : <?= e(montant($travail['avance'], devise())) ?>
           · Reste dû : <strong class="text-slate-800"><?= e(montant(num($travail['total_ttc']) - num($travail['avance']), devise())) ?></strong></p>
      <?php else: ?>
        <p><?= e($travail['numero']) ?></p>
      <?php endif; ?>
      <p>Édité le <?= e(dateHeureFr('now')) ?></p>
    </footer>
  </div>
</div>
<?php });
