<?php
/** Modification d'un chèque / effet et suivi de son statut. */

exigerSession();

$id = $params['id'];
$effet = un('SELECT * FROM effet WHERE id = ?', [$id]);
if (!$effet) introuvable('Pièce introuvable');

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'statut') {
        $statut = (string) champ('statut', '');
        if (isset(STATUTS_EFFET[$statut])) {
            modifier('effet', $id, [
                'statut'            => $statut,
                'date_encaissement' => $statut === 'ENCAISSE' ? maintenant() : null,
            ]);
        }
        rediriger('/effets/' . $id, 'Statut mis à jour.');
    }

    $montant = champNombre('montant');
    $numero = (string) champ('numero', '');
    $echeance = (string) champ('date_echeance', '');

    if ($numero === '') $erreur = 'Le numéro est obligatoire';
    elseif ($montant <= 0) $erreur = 'Le montant doit être positif';
    elseif ($echeance === '') $erreur = "La date d'échéance est obligatoire";
    else {
        modifier('effet', $id, [
            'type'          => champ('type') === 'EFFET' ? 'EFFET' : 'CHEQUE',
            'sens'          => champ('sens') === 'EMIS' ? 'EMIS' : 'RECU',
            'numero'        => $numero,
            'banque'        => ouNull(champ('banque')),
            'montant'       => $montant,
            'date_emission' => horodatage(champ('date_emission')) ?? $effet['date_emission'],
            'date_echeance' => horodatage($echeance),
            'tiers_id'      => ouNull(champ('tiers_id')),
            'note'          => ouNull(champ('note')),
        ]);
        rediriger('/effets/' . $id, 'Pièce enregistrée.');
    }

    $effet = array_merge($effet, [
        'numero' => $numero, 'montant' => $montant, 'date_echeance' => $echeance,
    ]);
}

$tiers = tous('SELECT id, nom FROM tiers WHERE actif = 1 ORDER BY nom');
$document = $effet['document_id']
    ? un('SELECT id, type, numero FROM document WHERE id = ?', [$effet['document_id']])
    : null;

page('Chèque / effet ' . $effet['numero'], function () use ($effet, $tiers, $document, $erreur) {
    echo enTetePage(
        ($effet['type'] === 'CHEQUE' ? 'Chèque' : 'Effet') . ' n° ' . $effet['numero'],
        'Échéance ' . dateFr($effet['date_echeance']) . ' · ' . montant($effet['montant'], devise()),
        boutonLien($effet['type'] === 'CHEQUE' ? '/effets/cheques' : '/effets/effets', 'Retour',
            ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    $optionsTiers = ['' => '— Aucun —'];
    foreach ($tiers as $t) $optionsTiers[$t['id']] = $t['nom'];
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?= carteDebut('Informations', ['actions' => badgeStatut(STATUTS_EFFET, $effet['statut'])]) ?>
          <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <?= champCsrf() ?>
            <?php if ($erreur) echo '<div class="sm:col-span-2">' . alerte(e($erreur), 'erreur') . '</div>'; ?>
            <?= champBloc('Type', listeDeroulante('type', ['CHEQUE' => 'Chèque', 'EFFET' => 'Effet'], $effet['type'])) ?>
            <?= champBloc('Sens', listeDeroulante('sens', ['RECU' => 'Reçu du client', 'EMIS' => 'Émis au fournisseur'], $effet['sens'])) ?>
            <?= champBloc('Numéro', saisie('numero', $effet['numero'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= champBloc('Banque', saisie('banque', $effet['banque'])) ?>
            <?= champBloc('Montant', saisie('montant', num($effet['montant']), ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => '0.01']]), ['obligatoire' => true]) ?>
            <?= champBloc('Tiers', listeDeroulante('tiers_id', $optionsTiers, $effet['tiers_id'])) ?>
            <?= champBloc("Date d'émission", saisie('date_emission', dateInput($effet['date_emission']), ['type' => 'date'])) ?>
            <?= champBloc("Date d'échéance", saisie('date_echeance', dateInput($effet['date_echeance']), ['type' => 'date'])) ?>
            <?= champBloc('Note', zoneTexte('note', $effet['note'], ['lignes' => 2]), ['classe' => 'sm:col-span-2']) ?>
            <div class="sm:col-span-2 flex justify-end"><?= bouton('Enregistrer') ?></div>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5">
        <?= carteDebut('Suivi') ?>
          <div class="space-y-2">
            <?php foreach (['ENCAISSE' => 'Marquer encaissé', 'REJETE' => 'Marquer rejeté',
                            'EN_ATTENTE' => 'Remettre en attente', 'ANNULE' => 'Annuler'] as $statut => $libelle): ?>
              <?php if ($effet['statut'] === $statut) continue; ?>
              <?= actionBouton('statut', ['statut' => $statut], $libelle, [
                  'variante' => $statut === 'ENCAISSE' ? 'succes' : 'secondaire',
                  'classe' => 'w-full',
              ]) ?>
            <?php endforeach; ?>
          </div>
          <?php if ($effet['date_encaissement']): ?>
            <p class="mt-3 text-xs text-slate-500">Encaissé le <?= e(dateFr($effet['date_encaissement'])) ?></p>
          <?php endif; ?>
        <?= carteFin() ?>

        <?php if ($document): ?>
          <?= carteDebut('Document lié') ?>
            <a href="<?= e(u(urlDocument($document['type'], $document['id']))) ?>" class="text-marine-600 hover:underline">
              <?= e(meta($document['type'])['label'] . ' ' . $document['numero']) ?> →
            </a>
          <?= carteFin() ?>
        <?php endif; ?>
      </div>
    </div>
<?php });
