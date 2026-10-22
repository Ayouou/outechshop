<?php
/**
 * Console — sauvegardes des sociétés.
 *
 * `/console/sauvegardes` liste les espaces installés ; `/console/sauvegardes/{id}`
 * renvoie le fichier JSON complet d'une société — le même format que le bouton
 * « Télécharger la sauvegarde » du client, et que celui de l'application
 * d'origine. Rien n'est conservé sur le serveur : le fichier part au navigateur.
 */

exigerConsole();

if (isset($params['id'])) {
    $societe = un('SELECT * FROM saas_societe WHERE id = ?', [$params['id']]);
    if (!$societe) introuvable('Société introuvable');
    if (empty($societe['base'])) {
        rediriger('/console/societes/' . $societe['id'], "Cette société n'a pas encore d'espace.", 'erreur');
    }

    $donnees = dansBase($societe['base'], fn() => exporterTout());
    journaliser('sauvegarde', $societe['id'], 'Sauvegarde téléchargée depuis la console.');

    $nom = 'outech-' . $societe['code'] . '-' . date('Y-m-d-His') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$societes = tous("SELECT * FROM saas_societe WHERE base IS NOT NULL AND base <> '' ORDER BY nom");

pageConsole('Sauvegardes', function () use ($societes) {
    echo enTetePage('Sauvegardes des sociétés',
        'Un fichier JSON par société : documents, stock, tiers, règlements et photos.');

    echo '<div class="mb-4">' . alerte(
        'Chaque fichier contient <strong>toutes</strong> les données de la société concernée, et rien '
        . 'de celles des autres. Il se restaure depuis <em>Paramètres → Sauvegarde</em> de son espace.',
        'info') . '</div>';
    ?>

    <?= carteDebut(null, ['sans_padding' => true]) ?>
      <?= tableauDebut(['Société', 'Base', 'Situation', 'Documents', 'Poids', 'Dernière écriture', '']) ?>
        <?php foreach ($societes as $s): $stats = statsSociete($s); ?>
          <tr>
            <td class="<?= CLASSES_TD ?>">
              <a class="font-medium text-marine-700 hover:underline" href="<?= e(u('/console/societes/' . $s['id'])) ?>"><?= e($s['nom']) ?></a>
              <div class="text-xs text-slate-500"><?= e($s['code']) ?></div>
            </td>
            <td class="<?= CLASSES_TD ?> font-mono text-xs"><?= e($s['base']) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= badgeSituation($s) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= e(nombre($stats['documents'])) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= e(poids((int) $stats['taille'])) ?></td>
            <td class="<?= CLASSES_TD ?>"><?= $stats['derniere_activite'] ? e(dateFr($stats['derniere_activite'])) : '—' ?></td>
            <td class="<?= CLASSES_TD ?> text-right">
              <?= boutonLien('/console/sauvegardes/' . $s['id'], 'Télécharger', ['variante' => 'secondaire', 'icone' => 'telecharger']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$societes) echo ligneVide(7, 'Aucun espace installé'); ?>
      <?= tableauFin() ?>
    <?= carteFin() ?>
<?php });
