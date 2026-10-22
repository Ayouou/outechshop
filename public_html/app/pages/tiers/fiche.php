<?php
/** Fiche d'un tiers : coordonnées, soldes et historique. */

exigerSession();

$tiers = un(
    'SELECT t.*, g.nom AS groupe_nom FROM tiers t
       LEFT JOIN groupe_client g ON g.id = t.groupe_id WHERE t.id = ?',
    [$params['id']],
);
if (!$tiers) introuvable('Tiers introuvable');

$documents = tous(
    'SELECT * FROM document WHERE tiers_id = ? ORDER BY date DESC LIMIT 25',
    [$tiers['id']],
);

$impayes = 0.0;
$chiffreAffaires = 0.0;
foreach ($documents as $doc) {
    if ($doc['statut'] === 'ANNULE') continue;
    if ($doc['statut_paiement'] !== 'PAYE') {
        $impayes += num($doc['total_ttc']) - num($doc['montant_paye']);
    }
    // Un bon de livraison déjà regroupé est exclu : sa facture porte les mêmes
    // marchandises et les compterait une seconde fois.
    if ($doc['type'] === 'FACTURE' || ($doc['type'] === 'BON_LIVRAISON' && $doc['statut'] !== 'CONVERTI')) {
        $chiffreAffaires += num($doc['total_ttc']);
    }
}

page('Fiche tiers', function () use ($tiers, $documents, $impayes, $chiffreAffaires) {
    $ligne = fn(string $libelle, ?string $valeur) =>
        '<div class="flex justify-between gap-4 border-b border-slate-100 py-2 last:border-0">'
        . '<dt class="text-sm text-slate-500">' . e($libelle) . '</dt>'
        . '<dd class="text-right text-sm font-medium text-slate-800">' . e($valeur ?: '—') . '</dd></div>';

    echo enTetePage(
        $tiers['nom'],
        $tiers['code'] . ' · ' . ($tiers['nom_entreprise'] ?: 'Particulier'),
        boutonLien('/tiers/' . $tiers['id'] . '/modifier', 'Modifier', ['variante' => 'secondaire', 'icone' => 'crayon']),
    );
    ?>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-5">
        <?= carteDebut('Coordonnées') ?>
          <dl>
            <?= $ligne('Type', TYPES_TIERS[$tiers['type']] ?? $tiers['type']) ?>
            <?= $ligne('Téléphone', $tiers['telephone']) ?>
            <?= $ligne('Mobile', $tiers['mobile']) ?>
            <?= $ligne('E-mail', $tiers['email']) ?>
            <?= $ligne('Adresse', $tiers['adresse']) ?>
            <?= $ligne('Ville', $tiers['ville']) ?>
          </dl>
        <?= carteFin() ?>

        <?= carteDebut('Informations légales') ?>
          <dl>
            <?= $ligne('ICE', $tiers['ice']) ?>
            <?= $ligne('RC', $tiers['rc']) ?>
            <?= $ligne('Groupe', $tiers['groupe_nom']) ?>
            <?= $ligne('Conditions de paiement', $tiers['conditions_paiement']) ?>
            <?= $ligne('Plafond de crédit', montant($tiers['plafond_credit'], devise())) ?>
            <?= $ligne("Solde d'ouverture", montant($tiers['solde_ouverture'], devise())) ?>
          </dl>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5 lg:col-span-2">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <?= tuile("Chiffre d'affaires", montant($chiffreAffaires, devise())) ?>
          <?= tuile('Solde dû', montant($impayes, devise()), ['couleur' => 'text-rose-600']) ?>
          <?= tuile('Documents', (string) count($documents)) ?>
        </div>

        <?= carteDebut('Historique des documents', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Date', 'Type', 'Numéro', ['libelle' => 'Total', 'classe' => 'text-right'], ['libelle' => 'Payé', 'classe' => 'text-right'], 'Statut']) ?>
          <?php if (!$documents) echo ligneVide(6); ?>
          <?php foreach ($documents as $doc): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($doc['date'])) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e(meta($doc['type'])['label']) ?></td>
              <td class="<?= CLASSES_TD ?>">
                <a href="<?= e(u(urlDocument($doc['type'], $doc['id']))) ?>" class="font-medium text-marine-600 hover:underline"><?= e($doc['numero']) ?></a>
              </td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($doc['total_ttc'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($doc['montant_paye'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_PAIEMENT, $doc['statut_paiement']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
