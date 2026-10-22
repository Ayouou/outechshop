<?php
/**
 * Import d'articles depuis un fichier Excel ou CSV.
 *
 * Le fichier est analysé et présenté en aperçu avant toute écriture. La
 * correspondance se fait sur la référence ou le code-barres : un article connu
 * est mis à jour, jamais dupliqué. Catégories et marques absentes sont créées
 * à la volée.
 */

$session = exigerSession();

/** Colonnes acceptées, insensibles à la casse et aux accents. */
const COLONNES_IMPORT = [
    'nom' => 'nom', 'designation' => 'nom', 'article' => 'nom',
    'reference' => 'reference', 'ref' => 'reference', 'sku' => 'reference',
    'codebarre' => 'code_barre', 'codebarres' => 'code_barre', 'ean' => 'code_barre',
    'categorie' => 'categorie', 'famille' => 'categorie',
    'marque' => 'marque',
    'unite' => 'unite',
    'prixachat' => 'prix_achat', 'achat' => 'prix_achat',
    'prixvente' => 'prix_vente', 'vente' => 'prix_vente', 'prix' => 'prix_vente',
    'tva' => 'tva',
    'seuilalerte' => 'stock_alerte', 'alerte' => 'stock_alerte',
    'stock' => 'stock_initial', 'stockinitial' => 'stock_initial', 'quantite' => 'stock_initial',
];

function normaliserEntete(string $texte): string
{
    $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte) ?: $texte;
    return preg_replace('/[^a-z0-9]/', '', strtolower($sans)) ?? '';
}

$erreur = null;
$message = null;
$apercu = [];
$donneesJson = '';

if (estPost() && champ('_action') === 'analyser') {
    $fichier = $_FILES['fichier'] ?? null;

    if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK || $fichier['size'] === 0) {
        $erreur = 'Sélectionnez un fichier .xlsx ou .csv.';
    } elseif ($fichier['size'] > 5 * 1024 * 1024) {
        $erreur = 'Fichier trop volumineux (5 Mo maximum).';
    } else {
        try {
            $lignesFichier = lireTableur($fichier['tmp_name'], $fichier['name']);
        } catch (RuntimeException $e) {
            $lignesFichier = [];
            $erreur = $e->getMessage();
        }

        if (!$erreur && count($lignesFichier) < 2) {
            $erreur = 'Le fichier ne contient aucune ligne de données.';
        }

        if (!$erreur) {
            // En-têtes : on associe chaque colonne à un champ connu.
            $entetes = [];
            foreach ($lignesFichier[0] as $index => $cellule) {
                $champ = COLONNES_IMPORT[normaliserEntete((string) $cellule)] ?? null;
                if ($champ) $entetes[$index] = $champ;
            }

            if (!in_array('nom', $entetes, true)) {
                $erreur = 'Colonne « Nom » introuvable. En-têtes reconnus : Nom, Référence, '
                    . 'Code-barres, Catégorie, Marque, Unité, Prix achat, Prix vente, TVA, '
                    . 'Seuil alerte, Stock.';
            } else {
                $references = [];
                $codesBarres = [];
                foreach (tous('SELECT reference, code_barre FROM article') as $a) {
                    $references[mb_strtolower($a['reference'])] = true;
                    if ($a['code_barre']) $codesBarres[mb_strtolower($a['code_barre'])] = true;
                }
                $tvaDefaut = num(societe()['tva_defaut']);

                $lignes = [];
                $vues = [];

                foreach (array_slice($lignesFichier, 1) as $i => $rangee) {
                    $brut = [];
                    foreach ($entetes as $index => $champ) $brut[$champ] = $rangee[$index] ?? '';

                    $nom = trim((string) ($brut['nom'] ?? ''));
                    if ($nom === '') continue; // ligne vide

                    $numero = $i + 2;
                    $reference = trim((string) ($brut['reference'] ?? '')) ?: 'IMP-' . ($i + 1);
                    $codeBarre = ouNull($brut['code_barre'] ?? null);

                    $erreurLigne = isset($vues[mb_strtolower($reference)])
                        ? 'Référence en double dans le fichier' : null;
                    $vues[mb_strtolower($reference)] = true;

                    $existant = isset($references[mb_strtolower($reference)])
                        || ($codeBarre !== null && isset($codesBarres[mb_strtolower($codeBarre)]));

                    $lignes[] = [
                        'ligne'         => $numero,
                        'nom'           => $nom,
                        'reference'     => $reference,
                        'code_barre'    => $codeBarre,
                        'categorie'     => ouNull($brut['categorie'] ?? null),
                        'marque'        => ouNull($brut['marque'] ?? null),
                        'unite'         => trim((string) ($brut['unite'] ?? '')) ?: 'Pièce',
                        'prix_achat'    => nombreSaisi($brut['prix_achat'] ?? null, 0),
                        'prix_vente'    => nombreSaisi($brut['prix_vente'] ?? null, 0),
                        'tva'           => nombreSaisi($brut['tva'] ?? null, $tvaDefaut),
                        'stock_alerte'  => nombreSaisi($brut['stock_alerte'] ?? null, 0),
                        'stock_initial' => nombreSaisi($brut['stock_initial'] ?? null, 0),
                        'existant'      => $existant,
                        'erreur'        => $erreurLigne,
                    ];
                }

                if (!$lignes) {
                    $erreur = 'Aucune ligne exploitable : la colonne « Nom » est vide partout.';
                } else {
                    $valides = array_values(array_filter($lignes, fn($l) => !$l['erreur']));
                    $creations = count(array_filter($valides, fn($l) => !$l['existant']));

                    $apercu = array_slice($lignes, 0, 200);
                    $donneesJson = json_encode($valides, JSON_UNESCAPED_UNICODE);
                    $message = count($valides) . ' ligne(s) prête(s) : ' . $creations
                        . ' création(s), ' . (count($valides) - $creations) . ' mise(s) à jour.';
                }
            }
        }
    }
}

if (estPost() && champ('_action') === 'confirmer') {
    $lignes = json_decode((string) champ('donnees_json', '[]'), true);

    if (!is_array($lignes) || !$lignes) {
        $erreur = "Données d'import illisibles, relancez l'analyse du fichier.";
    } else {
        $crees = 0;
        $majs = 0;

        // Catégories et marques créées à la volée, en amont pour éviter les doublons.
        $categories = [];
        $marques = [];
        foreach ($lignes as $ligne) {
            foreach ([['categorie', 'categorie'], ['marque', 'marque']] as [$cle, $table]) {
                $nom = $ligne[$cle] ?? null;
                if (!$nom) continue;
                $cache = $cle === 'categorie' ? $categories : $marques;
                if (isset($cache[$nom])) continue;

                $id = valeur("SELECT id FROM $table WHERE nom = ?", [$nom]) ?: inserer($table, ['nom' => $nom]);
                if ($cle === 'categorie') $categories[$nom] = $id;
                else $marques[$nom] = $id;
            }
        }

        foreach ($lignes as $ligne) {
            $commun = [
                'nom'          => $ligne['nom'],
                'code_barre'   => $ligne['code_barre'],
                'unite'        => $ligne['unite'],
                'prix_achat'   => $ligne['prix_achat'],
                'prix_vente'   => $ligne['prix_vente'],
                'tva'          => $ligne['tva'],
                'stock_alerte' => $ligne['stock_alerte'],
                'categorie_id' => $ligne['categorie'] ? ($categories[$ligne['categorie']] ?? null) : null,
                'marque_id'    => $ligne['marque'] ? ($marques[$ligne['marque']] ?? null) : null,
            ];

            $existant = $ligne['code_barre']
                ? valeur('SELECT id FROM article WHERE reference = ? OR code_barre = ? LIMIT 1', [$ligne['reference'], $ligne['code_barre']])
                : valeur('SELECT id FROM article WHERE reference = ? LIMIT 1', [$ligne['reference']]);

            if ($existant) {
                modifier('article', (string) $existant, $commun);
                $majs++;
                continue;
            }

            transaction(function () use ($commun, $ligne, $session) {
                $articleId = inserer('article', $commun + ['reference' => $ligne['reference']]);

                if ($ligne['stock_initial'] != 0) {
                    appliquerMouvement([
                        'article_id' => $articleId,
                        'lieu_id'    => $session['lieu_id'],
                        'type'       => 'AJUSTEMENT',
                        'quantite'   => $ligne['stock_initial'],
                        'note'       => 'Import de fichier',
                        'user_id'    => $session['user_id'],
                    ]);
                }
            });
            $crees++;
        }

        rediriger('/stock/articles', "Import terminé : $crees article(s) créé(s), $majs mis à jour.");
    }
}

page("Importer des articles", function () use ($erreur, $message, $apercu, $donneesJson) {
    echo enTetePage('Importer des articles', 'Fichier Excel (.xlsx) ou CSV — aperçu avant écriture',
        boutonLien('/stock/articles', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']));
    ?>

    <div class="space-y-5">
      <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>
      <?php if ($message) echo alerte(e($message), 'succes'); ?>

      <?= carteDebut('1. Choisir le fichier', ['sous_titre' => 'Colonnes reconnues : Nom, Référence, Code-barres, Catégorie, Marque, Unité, Prix achat, Prix vente, TVA, Seuil alerte, Stock']) ?>
        <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
          <?= champCsrf() ?>
          <input type="hidden" name="_action" value="analyser">
          <div class="flex-1 min-w-[16rem]">
            <label class="mb-1.5 block text-sm font-medium text-slate-700">Fichier</label>
            <input type="file" name="fichier" accept=".xlsx,.csv" required
                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
          </div>
          <?= bouton('Analyser', ['icone' => 'televerser']) ?>
        </form>
      <?= carteFin() ?>

      <?php if ($apercu): ?>
        <?= carteDebut('2. Aperçu', ['sous_titre' => 'Rien n\'est encore écrit en base', 'sans_padding' => true]) ?>
          <?= tableauDebut(['Ligne', 'Nom', 'Référence', 'Catégorie', 'Marque',
              ['libelle' => 'Prix vente', 'classe' => 'text-right'],
              ['libelle' => 'Stock', 'classe' => 'text-right'], 'État']) ?>
          <?php foreach ($apercu as $ligne): ?>
            <tr class="<?= $ligne['erreur'] ? 'bg-rose-50/60' : 'hover:bg-slate-50' ?>">
              <td class="<?= CLASSES_TD ?> text-slate-400"><?= (int) $ligne['ligne'] ?></td>
              <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($ligne['nom']) ?></td>
              <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($ligne['reference']) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($ligne['categorie'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($ligne['marque'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($ligne['prix_vente'], devise())) ?></td>
              <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['stock_initial'])) ?></td>
              <td class="<?= CLASSES_TD ?>">
                <?= $ligne['erreur']
                    ? badge($ligne['erreur'], 'rouge')
                    : badge($ligne['existant'] ? 'Mise à jour' : 'Création', $ligne['existant'] ? 'orange' : 'vert') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>

          <form method="post" class="flex justify-end border-t border-slate-100 px-5 py-3">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="confirmer">
            <input type="hidden" name="donnees_json" value="<?= e($donneesJson) ?>">
            <?= bouton("Confirmer l'import", ['icone' => 'coche']) ?>
          </form>
        <?= carteFin() ?>
      <?php endif; ?>
    </div>
<?php });
