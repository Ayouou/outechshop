<?php
/**
 * Sauvegarde et restauration de la base (administrateur uniquement).
 *
 * Le fichier contient toutes les tables, photos comprises. La restauration vide
 * la base et réinsère tout dans une seule transaction : le moindre incident
 * laisse la base intacte.
 */

exigerRole('ADMIN');

$erreur = null;
$compteurs = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'exporter') {
        $sauvegarde = exporterTout();
        $nom = 'outech-sauvegarde-' . date('Y-m-d-His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nom . '"');
        echo json_encode($sauvegarde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'restaurer') {
        $fichier = $_FILES['fichier'] ?? null;

        if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK || $fichier['size'] === 0) {
            $erreur = 'Sélectionnez un fichier de sauvegarde (.json).';
        } else {
            try {
                $donnees = json_decode((string) file_get_contents($fichier['tmp_name']), true);
                $sauvegarde = verifierSauvegarde($donnees);
                $compteurs = restaurerTout($sauvegarde);

                // Le compte qui vient de restaurer n'existe plus : il a été
                // remplacé par ceux du fichier. Garder sa session ouverte
                // laisserait une identité fantôme, et le premier document créé
                // échouerait sur un lieu d'affaires disparu.
                fermerSession();
                rediriger('/login', 'Restauration terminée : ' . array_sum($compteurs)
                    . " ligne(s) réinsérée(s). Reconnectez-vous avec un compte du fichier restauré.");
            } catch (RuntimeException $e) {
                $erreur = $e->getMessage();
            } catch (Throwable $e) {
                $erreur = 'Restauration impossible : ' . $e->getMessage() . ' — la base est restée intacte.';
            }
        }
    }
}

$statistiques = [];
foreach (SAUVEGARDE_ORDRE as $modele => $table) {
    $statistiques[$modele] = (int) valeur('SELECT COUNT(*) FROM `' . $table . '`');
}

page('Sauvegarde des données', function () use ($erreur, $compteurs, $statistiques) {
    echo enTetePage('Sauvegarde des données', 'Export complet et restauration à l\'identique');
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-5 lg:col-span-2">
        <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

        <?php if ($compteurs): ?>
          <?= alerte('Restauration terminée : ' . array_sum($compteurs) . ' ligne(s) réinsérée(s).', 'succes') ?>
        <?php endif; ?>

        <?= carteDebut('Exporter', ['sous_titre' => 'Un fichier JSON unique, photos comprises']) ?>
          <p class="mb-4 text-sm text-slate-600">
            Le fichier contient toutes les tables dans un ordre où chaque ligne suit celles dont elle
            dépend. Il se restaure ici même, ou depuis une autre installation.
          </p>
          <form method="post">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="exporter">
            <?= bouton('Télécharger la sauvegarde', ['icone' => 'telecharger']) ?>
          </form>
        <?= carteFin() ?>

        <?= carteDebut('Restaurer', ['sous_titre' => 'La base actuelle sera intégralement remplacée']) ?>
          <?= alerte('<strong>Attention :</strong> la restauration efface toutes les données existantes '
              . '(y compris les comptes utilisateurs) et les remplace par celles du fichier. '
              . "L'opération se joue dans une seule transaction : en cas d'incident, rien n'est écrit. "
              . 'Une fois terminée, vous serez déconnecté : reconnectez-vous avec un compte du fichier restauré.', 'attention') ?>

          <form method="post" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-end gap-3"
                onsubmit="return confirm('Remplacer toutes les données actuelles par celles du fichier ?')">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="restaurer">
            <div class="flex-1 min-w-[16rem]">
              <label class="mb-1.5 block text-sm font-medium text-slate-700">Fichier de sauvegarde</label>
              <input type="file" name="fichier" accept=".json,application/json" required
                     class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
            </div>
            <?= bouton('Restaurer', ['variante' => 'danger', 'icone' => 'televerser']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <?= carteDebut('Contenu actuel', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Table', ['libelle' => 'Lignes', 'classe' => 'text-right']]) ?>
        <?php foreach ($statistiques as $modele => $n): ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?>"><?= e($modele) ?></td>
            <td class="<?= CLASSES_TD ?> text-right <?= $n ? '' : 'text-slate-400' ?>"><?= $n ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
