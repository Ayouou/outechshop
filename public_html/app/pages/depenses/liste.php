<?php
/**
 * Liste des dépenses du lieu courant.
 *
 * Deux natures de lignes cohabitent ici. Les dépenses **saisies** sont l'argent
 * sorti de la caisse ; les **consommations** sont la valeur de la matière
 * partie du stock. Les additionner compterait deux fois le même papier — celui
 * qu'on a payé et celui qu'on a utilisé. Les totaux les gardent donc séparées,
 * et le filtre « nature » permet de ne regarder qu'un seul des deux.
 */

$session = exigerSession();

if (estPost() && champ('_action') === 'supprimer') {
    // Passe par `supprimerDepense` : une consommation doit rendre sa matière
    // au stock avant de disparaître.
    supprimerDepense((string) champ('id', ''));
    rediriger('/depenses', 'Dépense supprimée.');
}

$params = lireParams();
$args = [$session['lieu_id']];
$conditions = ['d.lieu_id = ?'];

$categorieFiltre = (string) param('categorie', '');
if ($categorieFiltre !== '') {
    $conditions[] = 'd.categorie_id = ?';
    $args[] = $categorieFiltre;
}

$natures = [
    'saisies'       => 'Dépenses saisies seulement',
    'consommations' => 'Consommations de stock seulement',
];
$natureFiltre = (string) param('nature', '');
if ($natureFiltre === 'saisies') {
    $conditions[] = "d.origine <> 'CONSOMMATION'";
} elseif ($natureFiltre === 'consommations') {
    $conditions[] = "d.origine = 'CONSOMMATION'";
}

$recherche = rechercheSur(['d.designation', 'd.numero', 'd.beneficiaire'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur("SELECT COUNT(*) FROM depense d WHERE $where", $args);
$cumul = cumulsDepenses($where, $args);

$depenses = tous(
    "SELECT d.*, c.nom AS categorie_nom FROM depense d
       LEFT JOIN categorie_depense c ON c.id = d.categorie_id
      WHERE $where ORDER BY d.date DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

$categories = tous('SELECT id, nom FROM categorie_depense ORDER BY nom');

page('Dépenses', function () use (
    $depenses, $total, $cumul, $params, $categories, $categorieFiltre, $natures, $natureFiltre
) {
    $sousTitre = montant($cumul['saisie_ttc'], devise()) . ' engagés · '
        . montant($cumul['saisie_paye'], devise()) . ' payés';
    if ($cumul['conso_nombre'] > 0) {
        $sousTitre .= ' · à part : ' . montant($cumul['conso_ttc'], devise()) . ' de stock consommé';
    }

    echo enTetePage('Gestion des dépenses', $sousTitre,
        boutonLien('/depenses/categories', 'Catégories', ['variante' => 'secondaire', 'icone' => 'etiquette'])
        . boutonLien('/depenses/consommation', 'Consommation', ['variante' => 'secondaire', 'icone' => 'boite'])
        . boutonLien('/depenses/nouvelle', 'Ajouter', ['icone' => 'plus']));

    echo carteDebut(null, ['sans_padding' => true]);

    $filtres = '<select name="categorie" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Toutes les catégories</option>';
    foreach ($categories as $c) {
        $filtres .= '<option value="' . e($c['id']) . '"' . ($categorieFiltre === $c['id'] ? ' selected' : '') . '>' . e($c['nom']) . '</option>';
    }
    $filtres .= '</select>';

    $filtres .= '<select name="nature" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Toutes natures</option>';
    foreach ($natures as $cle => $libelle) {
        $filtres .= '<option value="' . e($cle) . '"' . ($natureFiltre === $cle ? ' selected' : '') . '>' . e($libelle) . '</option>';
    }
    $filtres .= '</select>';

    echo barreOutils('/depenses', $params, $filtres
        . boutonLien('/api/export/depenses', 'Exporter', ['variante' => 'secondaire', 'icone' => 'telecharger']),
        'Désignation, numéro, bénéficiaire…');

    echo tableauDebut(['Action', 'Date', 'Numéro', 'Désignation', 'Catégorie', 'Bénéficiaire',
        ['libelle' => 'Montant TTC', 'classe' => 'text-right'],
        ['libelle' => 'Payé', 'classe' => 'text-right'], 'Statut']);

    if (!$depenses) echo ligneVide(9);

    foreach ($depenses as $depense) {
        $consommation = ($depense['origine'] ?? 'SAISIE') === 'CONSOMMATION';
        ?>
      <tr class="hover:bg-slate-50">
        <td class="<?= CLASSES_TD ?>">
          <?= menuActions([
              lienMenu('/depenses/' . $depense['id'], $consommation ? 'Voir le détail' : 'Modifier',
                  $consommation ? 'oeil' : 'crayon'),
              actionMenu('supprimer', ['id' => $depense['id']], $consommation ? 'Reprendre' : 'Effacer', [
                  'icone' => 'poubelle', 'classe' => 'text-rose-600',
                  'confirmation' => $consommation
                      ? "Reprendre « {$depense['designation']} » ? La matière revient au stock."
                      : "Supprimer « {$depense['designation']} » ?",
              ]),
          ]) ?>
        </td>
        <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($depense['date'])) ?></td>
        <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($depense['numero']) ?></td>
        <td class="<?= CLASSES_TD ?>">
          <a href="<?= e(u('/depenses/' . $depense['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($depense['designation']) ?></a>
          <?php
          if ($consommation) echo ' ' . badge('Stock consommé', 'orange');
          if ($depense['recurrente']) echo ' ' . badge('Récurrente', 'violet');
          ?>
        </td>
        <td class="<?= CLASSES_TD ?>"><?= e($depense['categorie_nom'] ?? '—') ?></td>
        <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($depense['beneficiaire'] ?? '—') ?></td>
        <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($depense['montant_ttc'], devise())) ?></td>
        <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($depense['montant_paye'], devise())) ?></td>
        <td class="<?= CLASSES_TD ?>">
          <?= $consommation ? '<span class="text-xs text-slate-400">—</span>' : badgeStatut(STATUTS_PAIEMENT, $depense['statut_paiement']) ?>
        </td>
      </tr>
    <?php }

    echo tableauFin();
    echo pagination('/depenses', $total, $params);
    echo carteFin();
});
