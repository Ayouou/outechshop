<?php
/** Bibliothèque de notes, procédures, contrats et fiches techniques. */

$session = exigerSession();

if (estPost() && champ('_action') === 'supprimer') {
    supprimer('documentation', (string) champ('id', ''));
    rediriger('/documentation', 'Fiche supprimée.');
}

$params = lireParams();
$args = [];
$conditions = [];

$typeFiltre = (string) param('type', '');
if (isset(TYPES_DOCUMENTATION[$typeFiltre])) {
    $conditions[] = 'd.type = ?';
    $args[] = $typeFiltre;
}

$recherche = rechercheSur(['d.titre', 'd.contenu', 'd.categorie'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur("SELECT COUNT(*) FROM documentation d WHERE $where", $args);

$fiches = tous(
    "SELECT d.*, t.nom AS tiers_nom, a.nom AS article_nom, doc.numero AS document_numero,
            doc.type AS document_type, us.nom AS user_nom
       FROM documentation d
       LEFT JOIN tiers t ON t.id = d.tiers_id
       LEFT JOIN article a ON a.id = d.article_id
       LEFT JOIN document doc ON doc.id = d.document_id
       LEFT JOIN utilisateur us ON us.id = d.user_id
      WHERE $where ORDER BY d.updated_at DESC LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

const COULEURS_DOCUMENTATION = [
    'NOTE' => 'gris', 'PROCEDURE' => 'bleu', 'CONTRAT' => 'violet',
    'FICHE_TECHNIQUE' => 'vert', 'AUTRE' => 'orange',
];

page('Documentation', function () use ($fiches, $total, $params, $typeFiltre) {
    echo enTetePage('Documentation', 'Notes, procédures, contrats et fiches techniques',
        boutonLien('/documentation/nouveau', 'Ajouter', ['icone' => 'plus']));

    echo carteDebut(null, ['sans_padding' => true]);

    $filtres = '<select name="type" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Tous les types</option>';
    foreach (TYPES_DOCUMENTATION as $cle => $libelle) {
        $filtres .= '<option value="' . e($cle) . '"' . ($typeFiltre === $cle ? ' selected' : '') . '>' . e($libelle) . '</option>';
    }
    $filtres .= '</select>';

    echo barreOutils('/documentation', $params, $filtres, 'Titre, contenu, catégorie…');

    echo tableauDebut(['Action', 'Titre', 'Type', 'Catégorie', 'Rattachée à', 'Mise à jour']);

    if (!$fiches) echo ligneVide(6, 'Aucune fiche');

    foreach ($fiches as $fiche) {
        $rattachement = '—';
        if ($fiche['tiers_nom']) $rattachement = 'Tiers : ' . $fiche['tiers_nom'];
        elseif ($fiche['article_nom']) $rattachement = 'Article : ' . $fiche['article_nom'];
        elseif ($fiche['document_numero']) $rattachement = 'Document : ' . $fiche['document_numero'];
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>">
            <?= menuActions([
                lienMenu('/documentation/' . $fiche['id'], 'Lire', 'oeil'),
                lienMenu('/documentation/' . $fiche['id'] . '/modifier', 'Modifier', 'crayon'),
                actionMenu('supprimer', ['id' => $fiche['id']], 'Effacer', [
                    'icone' => 'poubelle', 'classe' => 'text-rose-600',
                    'confirmation' => "Supprimer « {$fiche['titre']} » ?",
                ]),
            ]) ?>
          </td>
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u('/documentation/' . $fiche['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($fiche['titre']) ?></a>
          </td>
          <td class="<?= CLASSES_TD ?>"><?= badge(TYPES_DOCUMENTATION[$fiche['type']] ?? $fiche['type'], COULEURS_DOCUMENTATION[$fiche['type']] ?? 'gris') ?></td>
          <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($fiche['categorie'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> max-w-56 truncate text-slate-500"><?= e($rattachement) ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e(dateFr($fiche['updated_at'])) ?></td>
        </tr>
    <?php }

    echo tableauFin();
    echo pagination('/documentation', $total, $params);
    echo carteFin();
});
