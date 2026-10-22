<?php
/** Liste des clients / fournisseurs. Port de `ListeTiers.tsx`. */

exigerSession();

$chemin = cheminDemande();
if ($chemin === '/tiers') rediriger('/tiers/clients');

$client = $chemin !== '/tiers/fournisseurs';
$variante = $client ? 'CLIENT' : 'FOURNISSEUR';
$base = $client ? '/tiers/clients' : '/tiers/fournisseurs';

if (estPost()) {
    $id = (string) champ('id', '');
    $action = (string) champ('_action', '');

    if ($action === 'basculer') {
        $actif = valeur('SELECT actif FROM tiers WHERE id = ?', [$id]);
        if ($actif !== null) modifier('tiers', $id, ['actif' => $actif ? 0 : 1]);
        rediriger($base, 'Statut mis à jour.');
    }

    if ($action === 'supprimer') {
        $documents = (int) valeur('SELECT COUNT(*) FROM document WHERE tiers_id = ?', [$id]);
        if ($documents > 0) {
            // Un tiers rattaché à des documents est désactivé, jamais supprimé.
            modifier('tiers', $id, ['actif' => 0]);
            rediriger($base, 'Ce tiers possède des documents : il a été désactivé.', 'attention');
        }
        supprimer('tiers', $id);
        rediriger($base, 'Tiers supprimé.');
    }
}

$params = lireParams();
$args = [$variante];
$conditions = ['t.type IN (?, \'LES_DEUX\')'];
$recherche = rechercheSur(['t.nom', 't.nom_entreprise', 't.telephone', 't.email', 't.code', 't.ville'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur("SELECT COUNT(*) FROM tiers t WHERE $where", $args);

$lignes = tous(
    "SELECT t.*, g.nom AS groupe_nom FROM tiers t
       LEFT JOIN groupe_client g ON g.id = t.groupe_id
      WHERE $where ORDER BY t.created_at DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

// Solde dû : ce qui reste à encaisser (clients) ou à régler (fournisseurs).
$soldes = [];
$typeDoc = $client ? 'FACTURE' : 'ACHAT';
foreach (tous(
    "SELECT tiers_id, SUM(total_ttc) - SUM(montant_paye) AS solde FROM document
      WHERE type = ? AND statut <> 'ANNULE' AND statut_paiement <> 'PAYE' AND tiers_id IS NOT NULL
      GROUP BY tiers_id",
    [$typeDoc],
) as $ligne) {
    $soldes[$ligne['tiers_id']] = num($ligne['solde']);
}

page($client ? 'Clients' : 'Fournisseurs', function () use ($client, $base, $variante, $lignes, $soldes, $total, $params) {
    echo enTetePage(
        $client ? 'Clients' : 'Fournisseurs',
        $client ? 'Gérez vos clients' : 'Gérez vos fournisseurs',
        boutonLien("/tiers/nouveau?type=$variante", 'Ajouter', ['icone' => 'plus']),
    );

    echo carteDebut(null, ['sans_padding' => true]);
    echo barreOutils($base, $params, boutonLien('/api/export/tiers', 'Exporter', ['variante' => 'secondaire', 'icone' => 'telecharger']), 'Nom, téléphone, ICE…');

    $colonnes = ['Action', 'Code', 'Nom', 'Entreprise', 'Téléphone', 'Ville'];
    if ($client) $colonnes[] = 'Groupe';
    $colonnes[] = ['libelle' => 'Solde dû', 'classe' => 'text-right'];
    $colonnes[] = 'Statut';
    echo tableauDebut($colonnes);

    if (!$lignes) echo ligneVide(count($colonnes));

    foreach ($lignes as $tiers) { ?>
      <tr class="hover:bg-slate-50">
        <td class="<?= CLASSES_TD ?>">
          <?= menuActions([
              lienMenu('/tiers/' . $tiers['id'], 'Voir', 'oeil'),
              lienMenu('/tiers/' . $tiers['id'] . '/modifier', 'Modifier', 'crayon'),
              actionMenu('basculer', ['id' => $tiers['id']], $tiers['actif'] ? 'Désactiver' : 'Activer', ['icone' => 'flux']),
              actionMenu('supprimer', ['id' => $tiers['id']], 'Effacer', [
                  'icone' => 'poubelle',
                  'classe' => 'text-rose-600',
                  'confirmation' => "Supprimer « {$tiers['nom']} » ? S'il possède des documents, il sera seulement désactivé.",
              ]),
          ]) ?>
        </td>
        <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($tiers['code']) ?></td>
        <td class="<?= CLASSES_TD ?>">
          <a href="<?= e(u('/tiers/' . $tiers['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($tiers['nom']) ?></a>
        </td>
        <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($tiers['nom_entreprise'] ?? '—') ?></td>
        <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e($tiers['telephone'] ?: ($tiers['mobile'] ?: '—')) ?></td>
        <td class="<?= CLASSES_TD ?>"><?= e($tiers['ville'] ?? '—') ?></td>
        <?php if ($client): ?><td class="<?= CLASSES_TD ?>"><?= e($tiers['groupe_nom'] ?? '—') ?></td><?php endif; ?>
        <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($soldes[$tiers['id']] ?? 0, devise())) ?></td>
        <td class="<?= CLASSES_TD ?>"><?= badge($tiers['actif'] ? 'Actif' : 'Inactif', $tiers['actif'] ? 'vert' : 'gris') ?></td>
      </tr>
    <?php }

    echo tableauFin();
    echo pagination($base, $total, $params);
    echo carteFin();
});
