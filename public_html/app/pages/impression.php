<?php
/**
 * Impression d'un document : A4 (pièce comptable) ou rouleau thermique 80/58 mm.
 * Port de `src/app/impression/[id]`.
 *
 * Le navigateur gère l'export PDF ; aucune dépendance serveur supplémentaire.
 */

exigerSession();

// Les formats de sortie sont décrits par `FORMATS_IMPRESSION` (lib/documents).
$format = formatImpression(param('format'));

$document = un(
    'SELECT d.*, l.nom AS lieu_nom, l.adresse AS lieu_adresse, us.nom AS user_nom
       FROM document d
       JOIN lieu_affaire l ON l.id = d.lieu_id
       LEFT JOIN utilisateur us ON us.id = d.user_id
      WHERE d.id = ?',
    [$params['id']],
);
if (!$document) introuvable('Document introuvable');

$lignes = tous('SELECT * FROM ligne_document WHERE document_id = ? ORDER BY ordre', [$document['id']]);
$paiements = tous('SELECT * FROM paiement WHERE document_id = ? ORDER BY date', [$document['id']]);
$tiers = $document['tiers_id'] ? un('SELECT * FROM tiers WHERE id = ?', [$document['tiers_id']]) : null;

$societe = societe();
$meta = meta($document['type']);
$reste = num($document['total_ttc']) - num($document['montant_paye']);

$facteur = num($document['total_ht']) > 0
    ? (num($document['total_ht']) - num($document['remise'])) / num($document['total_ht'])
    : 1;
$ventilation = ventilationTVA($lignes, $facteur);

/** Nombre de caractères par ligne, pour les filets en pointillés. */
$colonnes = $format === '58' ? 32 : 42;
$largeur = FORMATS_IMPRESSION[$format]['largeur_mm'];
$marge = FORMATS_IMPRESSION[$format]['marge_mm'];

pageNue('Impression ' . $document['numero'], function () use (
    $document, $lignes, $paiements, $tiers, $societe, $meta, $reste, $ventilation,
    $format, $colonnes, $largeur, $marge
) { ?>
<div class="min-h-screen bg-slate-100 py-8">
  <!--
    `@page` ne se laisse pas cibler par une classe : la taille du support doit
    être écrite globalement. Cette règle arrive après outech.css, elle remplace
    donc le A4 par défaut quand un rouleau est demandé.
  -->
  <style>
    @media print {
      @page { size: <?= $largeur ?>mm <?= $format === 'a4' ? '297mm' : 'auto' ?>; margin: <?= $marge ?>mm; }
    }
  </style>

  <div class="sans-impression mx-auto mb-6 flex max-w-[210mm] flex-wrap items-center gap-2 px-4">
    <a href="<?= e(u(urlDocument($document['type'], $document['id']))) ?>" class="<?= classesBouton('secondaire') ?>">
      <?= icone('fleche-g', 'size-4') ?> Retour
    </a>
    <?php foreach (FORMATS_IMPRESSION as $cle => $f): ?>
      <a href="<?= e(u('/impression/' . $document['id'] . '?format=' . $cle)) ?>"
         class="<?= $cle === $format ? classesBouton('primaire') : classesBouton('secondaire') ?>"><?= e($f['label']) ?></a>
    <?php endforeach; ?>
    <button type="button" onclick="window.print()" class="<?= classesBouton('primaire', 'ml-auto') ?>">
      <?= icone('imprimante', 'size-4') ?> Imprimer / PDF
    </button>
  </div>

<?php if ($format === 'a4'): ?>
  <!-- Pièce comptable complète -->
  <div class="zone-impression mx-auto max-w-[210mm] bg-white p-10 shadow-lg">
    <header class="flex items-start justify-between gap-8 border-b-2 border-marine-600 pb-6">
      <div>
        <p class="text-2xl font-bold text-marine-800"><?= e($societe['nom'] ?? 'OUTECH') ?></p>
        <div class="mt-2 space-y-0.5 text-xs text-slate-600">
          <?php foreach ([['', $societe['adresse'] ?? null], ['', $societe['ville'] ?? null],
                          ['Tél : ', $societe['telephone'] ?? null], ['', $societe['email'] ?? null],
                          ['ICE : ', $societe['ice'] ?? null], ['RC : ', $societe['rc'] ?? null]] as [$prefixe, $valeur]): ?>
            <?php if ($valeur): ?><p><?= e($prefixe . $valeur) ?></p><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="text-right">
        <p class="text-xl font-bold tracking-wide text-slate-800 uppercase"><?= e($meta['label']) ?></p>
        <p class="mt-1 font-mono text-lg text-marine-700"><?= e($document['numero']) ?></p>
        <p class="mt-2 text-xs text-slate-600">Date : <?= e(dateFr($document['date'])) ?></p>
        <?php if ($document['date_echeance']): ?><p class="text-xs text-slate-600">Échéance : <?= e(dateFr($document['date_echeance'])) ?></p><?php endif; ?>
        <?php if ($document['reference']): ?><p class="text-xs text-slate-600">Réf. : <?= e($document['reference']) ?></p><?php endif; ?>
      </div>
    </header>

    <section class="mt-6 flex justify-between gap-8">
      <div class="min-w-[45%] rounded-lg bg-slate-50 p-4">
        <p class="mb-1 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">
          <?= e($meta['sens'] === 'VENTE' ? 'Client' : 'Fournisseur') ?>
        </p>
        <?php if ($tiers): ?>
          <div class="space-y-0.5 text-sm text-slate-700">
            <p class="font-semibold text-slate-900"><?= e($tiers['nom']) ?></p>
            <?php foreach ([['', $tiers['nom_entreprise']], ['', $tiers['adresse']], ['', $tiers['ville']],
                            ['Tél : ', $tiers['telephone']], ['ICE : ', $tiers['ice']]] as [$prefixe, $valeur]): ?>
              <?php if ($valeur): ?><p><?= e($prefixe . $valeur) ?></p><?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-sm text-slate-500">Client de passage</p>
        <?php endif; ?>
      </div>

      <div class="text-right text-xs text-slate-600">
        <p class="font-semibold text-slate-700"><?= e($document['lieu_nom']) ?></p>
        <?php if ($document['lieu_adresse']): ?><p><?= e($document['lieu_adresse']) ?></p><?php endif; ?>
      </div>
    </section>

    <table class="mt-6 w-full text-sm">
      <thead>
        <tr class="bg-marine-800 text-white">
          <th class="px-3 py-2 text-left font-medium">Désignation</th>
          <th class="w-20 px-3 py-2 text-right font-medium">Qté</th>
          <th class="w-28 px-3 py-2 text-right font-medium">P.U. HT</th>
          <th class="w-20 px-3 py-2 text-right font-medium">Rem.</th>
          <th class="w-20 px-3 py-2 text-right font-medium">TVA</th>
          <th class="w-32 px-3 py-2 text-right font-medium">Total HT</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lignes as $i => $ligne): ?>
          <tr class="<?= $i % 2 ? 'bg-slate-50' : '' ?>">
            <td class="px-3 py-2"><?= e($ligne['designation']) ?></td>
            <td class="px-3 py-2 text-right"><?= e(nombre($ligne['quantite'])) ?></td>
            <td class="px-3 py-2 text-right"><?= e(nombre($ligne['prix_unitaire'])) ?></td>
            <td class="px-3 py-2 text-right"><?= e(pourcent($ligne['remise'])) ?></td>
            <td class="px-3 py-2 text-right"><?= e(pourcent($ligne['tva'])) ?></td>
            <td class="px-3 py-2 text-right font-medium"><?= e(nombre($ligne['total_ht'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <section class="mt-6 flex justify-between gap-8">
      <div class="flex-1">
        <?php if ($ventilation): ?>
          <table class="w-full max-w-xs text-xs">
            <thead>
              <tr class="border-b border-slate-300 text-slate-500">
                <th class="py-1 text-left font-medium">Taux</th>
                <th class="py-1 text-right font-medium">Base HT</th>
                <th class="py-1 text-right font-medium">Montant TVA</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ventilation as $ligne): ?>
                <tr class="border-b border-slate-100">
                  <td class="py-1"><?= e(pourcent($ligne['taux'])) ?></td>
                  <td class="py-1 text-right"><?= e(nombre($ligne['base'])) ?></td>
                  <td class="py-1 text-right"><?= e(nombre($ligne['montant'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <dl class="w-72 space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">Total HT</dt><dd><?= e(montant($document['total_ht'], devise())) ?></dd></div>
        <?php if (num($document['remise']) > 0): ?>
          <div class="flex justify-between"><dt class="text-slate-500">Remise</dt><dd>− <?= e(montant($document['remise'], devise())) ?></dd></div>
        <?php endif; ?>
        <div class="flex justify-between"><dt class="text-slate-500">Total TVA</dt><dd><?= e(montant($document['total_tva'], devise())) ?></dd></div>
        <?php if (num($document['frais_port']) > 0): ?>
          <div class="flex justify-between"><dt class="text-slate-500">Frais de port</dt><dd><?= e(montant($document['frais_port'], devise())) ?></dd></div>
        <?php endif; ?>
        <div class="flex justify-between border-t-2 border-marine-600 pt-2 text-base font-bold text-marine-800">
          <dt>Total TTC</dt><dd><?= e(montant($document['total_ttc'], devise())) ?></dd>
        </div>
        <?php if ($meta['payable']): ?>
          <div class="flex justify-between"><dt class="text-slate-500">Total payé</dt><dd><?= e(montant($document['montant_paye'], devise())) ?></dd></div>
          <div class="flex justify-between font-semibold"><dt class="text-slate-500">Reste à payer</dt><dd><?= e(montant($reste, devise())) ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>

    <?php if ($paiements): ?>
      <section class="mt-6">
        <p class="mb-2 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">Règlements</p>
        <table class="w-full text-xs">
          <thead>
            <tr class="border-b border-slate-300 text-slate-500">
              <th class="py-1 text-left font-medium">Date</th>
              <th class="py-1 text-left font-medium">Mode</th>
              <th class="py-1 text-left font-medium">Référence</th>
              <th class="py-1 text-right font-medium">Montant</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($paiements as $paiement): ?>
              <tr class="border-b border-slate-100">
                <td class="py-1"><?= e(dateFr($paiement['date'])) ?></td>
                <td class="py-1"><?= e(MODES_PAIEMENT[$paiement['mode']] ?? $paiement['mode']) ?></td>
                <td class="py-1"><?= e($paiement['reference'] ?? '—') ?></td>
                <td class="py-1 text-right"><?= e(nombre($paiement['montant'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>

    <?php if ($document['notes']): ?>
      <section class="mt-6 rounded-lg bg-slate-50 p-4 text-xs whitespace-pre-wrap text-slate-600"><?= e($document['notes']) ?></section>
    <?php endif; ?>

    <footer class="mt-10 border-t border-slate-200 pt-4 text-center text-[11px] text-slate-500">
      <?= e($societe['pied_facture'] ?? 'Merci de votre confiance.') ?>
      <?php if (!empty($societe['identifiant_fiscal'])): ?> · IF : <?= e($societe['identifiant_fiscal']) ?><?php endif; ?>
      <?php if (!empty($societe['patente'])): ?> · Patente : <?= e($societe['patente']) ?><?php endif; ?>
    </footer>
  </div>

<?php else: ?>
  <!--
    Ticket de caisse thermique : chasse fixe et noir uniquement — ces
    imprimantes ne rendent ni les gris ni les aplats de couleur, et
    l'alignement des colonnes n'est fiable qu'en chasse fixe.
  -->
  <?php
  $filet = fn() => '<p class="overflow-hidden whitespace-nowrap">' . str_repeat('-', $colonnes) . '</p>';
  $ligneTicket = fn(string $gauche, string $droite, bool $gras = false) =>
      '<p class="flex justify-between gap-2' . ($gras ? ' font-bold' : '') . '">'
      . '<span class="min-w-0 truncate">' . e($gauche) . '</span>'
      . '<span class="shrink-0 tabular-nums">' . e($droite) . '</span></p>';
  ?>
  <div class="zone-impression mx-auto bg-white p-3 font-mono text-[11px] leading-snug text-black shadow-lg" style="width: <?= $largeur ?>mm">
    <div class="text-center">
      <p class="text-sm font-bold uppercase"><?= e($societe['nom'] ?? 'OUTECH') ?></p>
      <?php if (!empty($societe['adresse'])): ?><p><?= e($societe['adresse']) ?></p><?php endif; ?>
      <?php if (!empty($societe['ville'])): ?><p><?= e($societe['ville']) ?></p><?php endif; ?>
      <?php if (!empty($societe['telephone'])): ?><p>Tel : <?= e($societe['telephone']) ?></p><?php endif; ?>
      <?php if (!empty($societe['ice'])): ?><p>ICE : <?= e($societe['ice']) ?></p><?php endif; ?>
    </div>

    <?= $filet() ?>
    <p class="text-center text-xs font-bold uppercase"><?= e($meta['label']) ?></p>
    <p class="text-center"><?= e($document['numero']) ?></p>
    <?= $filet() ?>

    <p><?= e(dateHeureFr($document['date'])) ?></p>
    <p>Lieu : <?= e($document['lieu_nom']) ?></p>
    <?php if ($document['user_nom']): ?><p>Caisse : <?= e($document['user_nom']) ?></p><?php endif; ?>
    <p>Client : <?= e($tiers['nom'] ?? 'Passage') ?></p>
    <?php if ($tiers && $tiers['ice']): ?><p>ICE client : <?= e($tiers['ice']) ?></p><?php endif; ?>

    <?= $filet() ?>

    <?php foreach ($lignes as $ligne): ?>
      <div class="mt-1">
        <p class="uppercase"><?= e($ligne['designation']) ?></p>
        <?= $ligneTicket('  ' . nombre($ligne['quantite']) . ' x ' . nombre($ligne['prix_unitaire']), nombre($ligne['total_ttc'])) ?>
      </div>
    <?php endforeach; ?>

    <?= $filet() ?>
    <?= $ligneTicket('Total HT', nombre($document['total_ht'])) ?>
    <?php if (num($document['remise']) > 0) echo $ligneTicket('Remise', '- ' . nombre($document['remise'])); ?>
    <?= $ligneTicket('TVA', nombre($document['total_tva'])) ?>
    <?php if (num($document['frais_port']) > 0) echo $ligneTicket('Frais de port', nombre($document['frais_port'])); ?>

    <?= $filet() ?>
    <?= $ligneTicket('TOTAL ' . mb_strtoupper(devise()), nombre($document['total_ttc']), true) ?>

    <?php if ($paiements): ?>
      <?= $filet() ?>
      <?php foreach ($paiements as $paiement): ?>
        <?= $ligneTicket(MODES_PAIEMENT[$paiement['mode']] ?? $paiement['mode'], nombre($paiement['montant'])) ?>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($meta['payable'] && $reste > 0.005) echo $ligneTicket('RESTE A PAYER', nombre($reste), true); ?>

    <?php if ($document['notes']): ?>
      <?= $filet() ?>
      <p class="whitespace-pre-wrap"><?= e($document['notes']) ?></p>
    <?php endif; ?>

    <?= $filet() ?>
    <div class="text-center">
      <p><?= e($societe['pied_facture'] ?? 'Merci de votre confiance.') ?></p>
      <?php if (!empty($societe['identifiant_fiscal'])): ?><p>IF : <?= e($societe['identifiant_fiscal']) ?></p><?php endif; ?>
      <?php if (!empty($societe['patente'])): ?><p>Patente : <?= e($societe['patente']) ?></p><?php endif; ?>
    </div>
    <!-- Marge basse : le massicot des thermiques coupe quelques millimètres trop haut. -->
    <p class="pt-6">&nbsp;</p>
  </div>
<?php endif; ?>
</div>
<?php });
