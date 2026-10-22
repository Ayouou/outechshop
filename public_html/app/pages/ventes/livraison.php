<?php
/** Suivi détaillé d'une livraison. */

exigerSession();

require_once __DIR__ . '/livraisons-fonctions.php';

$documentId = $params['id'];

$document = un(
    'SELECT d.*, t.nom AS tiers_nom, t.adresse AS tiers_adresse, t.telephone
       FROM document d LEFT JOIN tiers t ON t.id = d.tiers_id WHERE d.id = ?',
    [$documentId],
);
if (!$document) introuvable('Document introuvable');
if ($document['type'] !== 'BON_LIVRAISON') {
    introuvable('Seuls les bons de livraison peuvent être suivis.');
}

if (estPost()) {
    $statut = (string) champ('statut', 'EN_PREPARATION');
    if (!isset(STATUTS_LIVRAISON[$statut])) $statut = 'EN_PREPARATION';

    enregistrerLivraison($documentId, [
        'statut'          => $statut,
        'transporteur'    => champ('transporteur'),
        'numero_suivi'    => champ('numero_suivi'),
        'adresse'         => champ('adresse'),
        'contact'         => champ('contact'),
        'frais_livraison' => champNombre('frais_livraison'),
        'date_expedition' => champ('date_expedition'),
        'date_livraison'  => champ('date_livraison'),
        'note'            => champ('note'),
    ]);

    rediriger('/ventes/livraisons/' . $documentId, 'Livraison enregistrée.');
}

$livraison = un('SELECT * FROM livraison WHERE document_id = ?', [$documentId]) ?? [
    'statut' => 'EN_PREPARATION', 'transporteur' => '', 'numero_suivi' => '',
    'adresse' => $document['tiers_adresse'], 'contact' => $document['telephone'],
    'frais_livraison' => '0', 'date_expedition' => null, 'date_livraison' => null, 'note' => '',
];

$lignes = tous('SELECT designation, quantite FROM ligne_document WHERE document_id = ? ORDER BY ordre', [$documentId]);

page('Livraison ' . $document['numero'], function () use ($document, $livraison, $lignes) {
    echo enTetePage(
        'Livraison — ' . $document['numero'],
        dateFr($document['date']) . ' · ' . ($document['tiers_nom'] ?? 'Client de passage'),
        boutonLien('/ventes/livraisons', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g'])
        . boutonLien('/ventes/bons-livraison/' . $document['id'], 'Voir le bon', ['variante' => 'secondaire', 'icone' => 'oeil']),
    );

    $optionsStatut = [];
    foreach (STATUTS_LIVRAISON as $cle => $s) $optionsStatut[$cle] = $s['label'];
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?= carteDebut('Suivi logistique', ['actions' => badgeStatut(STATUTS_LIVRAISON, $livraison['statut'])]) ?>
          <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <?= champCsrf() ?>
            <?= champBloc('Statut', listeDeroulante('statut', $optionsStatut, $livraison['statut']),
                ['aide' => "Les dates se remplissent seules quand le statut avance"]) ?>
            <?= champBloc('Transporteur', saisie('transporteur', $livraison['transporteur'])) ?>
            <?= champBloc('Numéro de suivi', saisie('numero_suivi', $livraison['numero_suivi'])) ?>
            <?= champBloc('Contact sur place', saisie('contact', $livraison['contact'])) ?>
            <?= champBloc('Adresse de livraison', zoneTexte('adresse', $livraison['adresse'], ['lignes' => 2]), ['classe' => 'sm:col-span-2']) ?>
            <?= champBloc('Frais de livraison (' . devise() . ')', saisie('frais_livraison', num($livraison['frais_livraison']), ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0]])) ?>
            <div></div>
            <?= champBloc("Date d'expédition", saisie('date_expedition', dateInput($livraison['date_expedition']), ['type' => 'date'])) ?>
            <?= champBloc('Date de livraison', saisie('date_livraison', dateInput($livraison['date_livraison']), ['type' => 'date'])) ?>
            <?= champBloc('Note', zoneTexte('note', $livraison['note'], ['lignes' => 3]), ['classe' => 'sm:col-span-2']) ?>
            <div class="sm:col-span-2 flex justify-end"><?= bouton('Enregistrer le suivi') ?></div>
          </form>
        <?= carteFin() ?>
      </div>

      <?= carteDebut('Contenu du bon', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Article', ['libelle' => 'Qté', 'classe' => 'text-right']]) ?>
        <?php if (!$lignes) echo ligneVide(2); ?>
        <?php foreach ($lignes as $ligne): ?>
          <tr>
            <td class="<?= CLASSES_TD ?>"><?= e($ligne['designation']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['quantite'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
