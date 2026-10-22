<?php
/** Fiche d'un document : lignes, totaux, règlements, filiation. */

$session = exigerSession();

$sens = str_starts_with(cheminDemande(), '/ventes') ? 'VENTE' : 'ACHAT';
$type = typeDepuisChemin($sens, $params['chemin'] ?? '');
if (!$type) introuvable('Type de document inconnu');

$id = $params['id'];
$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'convertir') {
        $cible = (string) champ('cible', '');
        $resultat = convertirDocument($session, $id, $cible);
        if (isset($resultat['erreur'])) rediriger(urlDocument($type, $id), $resultat['erreur'], 'erreur');
        rediriger(urlDocument($cible, $resultat['id']), 'Document converti.');
    }

    if ($action === 'paiement') {
        $resultat = ajouterPaiement($session, $id, [
            'montant'       => champNombre('montant'),
            'mode'          => champ('mode') ?: 'ESPECES',
            'reference'     => champ('reference'),
            'banque'        => champ('banque'),
            'date_echeance' => champ('date_echeance'),
            'note'          => champ('note'),
        ]);
        if (isset($resultat['erreur'])) $erreur = $resultat['erreur'];
        else rediriger(urlDocument($type, $id), 'Règlement enregistré.');
    }

    if ($action === 'supprimer-paiement') {
        supprimerPaiement((string) champ('paiement_id', ''), $session);
        rediriger(urlDocument($type, $id), 'Règlement supprimé.');
    }
}

$document = un(
    'SELECT d.*, l.nom AS lieu_nom, us.nom AS user_nom FROM document d
       JOIN lieu_affaire l ON l.id = d.lieu_id
       LEFT JOIN utilisateur us ON us.id = d.user_id
      WHERE d.id = ?',
    [$id],
);
if (!$document || $document['type'] !== $type) introuvable('Document introuvable');

$meta = meta($document['type']);
$reste = num($document['total_ttc']) - num($document['montant_paye']);
$base = urlListe($document['type']);

$lignes = tous('SELECT * FROM ligne_document WHERE document_id = ? ORDER BY ordre', [$id]);
$paiements = tous(
    'SELECT p.*, us.nom AS user_nom FROM paiement p
       LEFT JOIN utilisateur us ON us.id = p.user_id
      WHERE p.document_id = ? ORDER BY p.date DESC',
    [$id],
);
$tiers = $document['tiers_id'] ? un('SELECT * FROM tiers WHERE id = ?', [$document['tiers_id']]) : null;
$parent = $document['parent_id'] ? un('SELECT id, type, numero FROM document WHERE id = ?', [$document['parent_id']]) : null;
$enfants = tous('SELECT id, type, numero FROM document WHERE parent_id = ?', [$id]);

page($meta['label'] . ' ' . $document['numero'], function () use (
    $document, $meta, $base, $lignes, $paiements, $tiers, $parent, $enfants, $reste, $erreur
) {
    $actions = '<a href="' . e(u('/impression/' . $document['id'])) . '" target="_blank" class="'
        . classesBouton('secondaire') . '">' . icone('imprimante', 'size-4') . 'Imprimer / PDF</a>';

    if ($document['statut'] !== 'CONVERTI' && $document['statut'] !== 'ANNULE') {
        $actions .= boutonLien("$base/{$document['id']}/modifier", 'Modifier', ['variante' => 'secondaire', 'icone' => 'crayon']);
    }
    if ($document['statut'] === 'VALIDE') {
        foreach ($meta['conversions'] as $cible) {
            $actions .= actionBouton('convertir', ['cible' => $cible],
                'Convertir en ' . meta($cible)['label'],
                ['icone' => 'flux', 'confirmation' => 'Convertir en ' . meta($cible)['label'] . ' ?']);
        }
    }

    echo enTetePage(
        $meta['label'] . ' ' . $document['numero'],
        dateFr($document['date']) . ' · ' . $document['lieu_nom'],
        $actions,
    );

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';

    $statuts = badgeStatut(STATUTS_DOCUMENT, $document['statut'])
        . ($meta['payable'] ? ' ' . badgeStatut(STATUTS_PAIEMENT, $document['statut_paiement']) : '');
    ?>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-5 lg:col-span-2">
        <?= carteDebut('Lignes du document', ['actions' => $statuts, 'sans_padding' => true]) ?>
          <?= tableauDebut(['Désignation',
              ['libelle' => 'Qté', 'classe' => 'text-right'],
              ['libelle' => 'P.U. HT', 'classe' => 'text-right'],
              ['libelle' => 'Remise', 'classe' => 'text-right'],
              ['libelle' => 'TVA', 'classe' => 'text-right'],
              ['libelle' => 'Total HT', 'classe' => 'text-right']]) ?>
          <?php if (!$lignes) echo ligneVide(6); ?>
          <?php foreach ($lignes as $ligne): ?>
            <tr>
              <td class="<?= CLASSES_TD ?>">
                <?php if ($ligne['article_id']): ?>
                  <a href="<?= e(u('/stock/articles/' . $ligne['article_id'])) ?>" class="text-marine-600 hover:underline"><?= e($ligne['designation']) ?></a>
                <?php else: ?>
                  <?= e($ligne['designation']) ?>
                <?php endif; ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['quantite'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($ligne['prix_unitaire'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(pourcent($ligne['remise'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(pourcent($ligne['tva'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($ligne['total_ht'], devise())) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>

          <div class="flex justify-end border-t border-slate-100 p-5">
            <dl class="w-full max-w-xs space-y-2 text-sm">
              <div class="flex justify-between"><dt class="text-slate-500">Total HT</dt><dd><?= e(montant($document['total_ht'], devise())) ?></dd></div>
              <?php if (num($document['remise']) > 0): ?>
                <div class="flex justify-between"><dt class="text-slate-500">Remise</dt><dd class="text-rose-600">− <?= e(montant($document['remise'], devise())) ?></dd></div>
              <?php endif; ?>
              <div class="flex justify-between"><dt class="text-slate-500">TVA</dt><dd><?= e(montant($document['total_tva'], devise())) ?></dd></div>
              <?php if (num($document['frais_port']) > 0): ?>
                <div class="flex justify-between"><dt class="text-slate-500">Frais de port</dt><dd><?= e(montant($document['frais_port'], devise())) ?></dd></div>
              <?php endif; ?>
              <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                <dt>Total TTC</dt><dd class="text-marine-700"><?= e(montant($document['total_ttc'], devise())) ?></dd>
              </div>
              <?php if ($meta['payable']): ?>
                <div class="flex justify-between"><dt class="text-slate-500">Payé</dt><dd class="text-emerald-600"><?= e(montant($document['montant_paye'], devise())) ?></dd></div>
                <div class="flex justify-between font-medium">
                  <dt class="text-slate-500">Reste à payer</dt>
                  <dd class="<?= $reste > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= e(montant($reste, devise())) ?></dd>
                </div>
              <?php endif; ?>
            </dl>
          </div>
        <?= carteFin() ?>

        <?php if ($meta['payable']): ?>
          <?= carteDebut('Règlements', ['sans_padding' => true]) ?>
            <?= tableauDebut(['Date', 'Mode', 'Référence', 'Encaissé par',
                ['libelle' => 'Montant', 'classe' => 'text-right'], '']) ?>
            <?php if (!$paiements) echo ligneVide(6, 'Aucun règlement enregistré'); ?>
            <?php foreach ($paiements as $paiement): ?>
              <tr>
                <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($paiement['date'])) ?></td>
                <td class="<?= CLASSES_TD ?>"><?= e(MODES_PAIEMENT[$paiement['mode']] ?? $paiement['mode']) ?></td>
                <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($paiement['reference'] ?? '—') ?></td>
                <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($paiement['user_nom'] ?? '—') ?></td>
                <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($paiement['montant'], devise())) ?></td>
                <td class="<?= CLASSES_TD ?> text-right">
                  <?= actionBouton('supprimer-paiement', ['paiement_id' => $paiement['id']], 'Supprimer', [
                      'variante' => 'fantome', 'classe' => 'text-rose-600 px-2 py-1',
                      'confirmation' => 'Supprimer ce règlement ?',
                  ]) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?= tableauFin() ?>
          <?= carteFin() ?>
        <?php endif; ?>
      </div>

      <div class="space-y-5">
        <?= carteDebut($meta['sens'] === 'VENTE' ? 'Client' : 'Fournisseur') ?>
          <?php if ($tiers): ?>
            <div class="space-y-1.5 text-sm">
              <p class="font-medium text-slate-900"><?= e($tiers['nom']) ?></p>
              <?php if ($tiers['nom_entreprise']): ?><p class="text-slate-500"><?= e($tiers['nom_entreprise']) ?></p><?php endif; ?>
              <?php if ($tiers['telephone']): ?><p class="text-slate-500"><?= e($tiers['telephone']) ?></p><?php endif; ?>
              <?php if ($tiers['adresse']): ?><p class="text-slate-500"><?= e($tiers['adresse']) ?></p><?php endif; ?>
              <?php if ($tiers['ice']): ?><p class="text-slate-500">ICE : <?= e($tiers['ice']) ?></p><?php endif; ?>
              <a href="<?= e(u('/tiers/' . $tiers['id'])) ?>" class="mt-2 inline-block text-marine-600 hover:underline">Voir la fiche →</a>
            </div>
          <?php else: ?>
            <p class="text-sm text-slate-400">Client de passage</p>
          <?php endif; ?>
        <?= carteFin() ?>

        <?php if ($meta['payable'] && $reste > 0.005 && $document['statut'] !== 'ANNULE'): ?>
          <?= carteDebut('Ajouter un règlement') ?>
            <form method="post" class="space-y-3">
              <?= champCsrf() ?>
              <input type="hidden" name="_action" value="paiement">
              <?= champBloc('Montant', saisie('montant', number_format($reste, 2, '.', ''), [
                  'type' => 'number', 'attrs' => ['step' => '0.01', 'min' => '0.01', 'max' => number_format($reste, 2, '.', ''), 'required' => true]]),
                  ['aide' => 'Reste à payer : ' . montant($reste, devise())]) ?>
              <?= champBloc('Mode', listeDeroulante('mode', MODES_PAIEMENT, 'ESPECES', ['id' => 'mode-paiement'])) ?>
              <div id="champs-effet" class="hidden space-y-3">
                <?= champBloc('Numéro du chèque / effet', saisie('reference', '')) ?>
                <?= champBloc('Banque', saisie('banque', '')) ?>
                <?= champBloc("Date d'échéance", saisie('date_echeance', dateInput('now'), ['type' => 'date'])) ?>
              </div>
              <?= bouton('Enregistrer le règlement', ['classe' => 'w-full']) ?>
            </form>
            <script>
              // Chèque et effet créent une pièce à suivre : leurs champs propres
              // n'apparaissent que dans ce cas.
              const mode = document.getElementById('mode-paiement');
              const bloc = document.getElementById('champs-effet');
              const basculer = () => bloc.classList.toggle('hidden', !['CHEQUE', 'EFFET'].includes(mode.value));
              mode.addEventListener('change', basculer);
              basculer();
            </script>
          <?= carteFin() ?>
        <?php endif; ?>

        <?php if ($parent || $enfants): ?>
          <?= carteDebut('Documents liés') ?>
            <ul class="space-y-2 text-sm">
              <?php if ($parent): ?>
                <li><span class="text-slate-500">Origine : </span>
                  <a href="<?= e(u(urlDocument($parent['type'], $parent['id']))) ?>" class="text-marine-600 hover:underline">
                    <?= e(meta($parent['type'])['label'] . ' ' . $parent['numero']) ?></a></li>
              <?php endif; ?>
              <?php foreach ($enfants as $enfant): ?>
                <li><span class="text-slate-500">Converti en : </span>
                  <a href="<?= e(u(urlDocument($enfant['type'], $enfant['id']))) ?>" class="text-marine-600 hover:underline">
                    <?= e(meta($enfant['type'])['label'] . ' ' . $enfant['numero']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?= carteFin() ?>
        <?php endif; ?>

        <?php if ($document['notes']): ?>
          <?= carteDebut('Notes') ?>
            <p class="text-sm whitespace-pre-wrap text-slate-600"><?= e($document['notes']) ?></p>
          <?= carteFin() ?>
        <?php endif; ?>
      </div>
    </div>
<?php });
