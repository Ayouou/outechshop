<?php
/**
 * Lecture et écriture de tableurs, sans bibliothèque externe.
 *
 * L'application d'origine s'appuyait sur `exceljs`. Ici : le CSV est lu et
 * écrit nativement, et le XLSX est ouvert avec `ZipArchive` + `SimpleXML` —
 * un .xlsx n'est qu'une archive de fichiers XML.
 */

/** Lit un fichier .csv ou .xlsx et renvoie un tableau de lignes (tableaux de cellules). */
function lireTableur(string $chemin, string $nomFichier): array
{
    if (str_ends_with(strtolower($nomFichier), '.csv')) return lireCsv($chemin);
    return lireXlsx($chemin);
}

function lireCsv(string $chemin): array
{
    $texte = (string) file_get_contents($chemin);
    // BOM UTF-8 en tête : sans le retirer, la première en-tête ne serait pas reconnue.
    $texte = preg_replace('/^\x{FEFF}/u', '', $texte) ?? $texte;

    $separateur = substr_count($texte, ';') > substr_count($texte, ',') ? ';' : ',';

    $lignes = [];
    foreach (preg_split('/\r\n|\r|\n/', $texte) ?: [] as $ligne) {
        if (trim($ligne) === '') continue;
        $lignes[] = array_map(
            fn($cellule) => trim(trim($cellule), '"'),
            str_getcsv($ligne, $separateur, '"', '\\'),
        );
    }
    return $lignes;
}

/**
 * Lit la première feuille d'un classeur .xlsx.
 * Les valeurs partagées (`sharedStrings.xml`) sont résolues, les formules
 * remplacées par leur dernier résultat calculé.
 */
function lireXlsx(string $chemin): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException("L'extension ZIP de PHP est absente : utilisez un fichier .csv.");
    }

    $zip = new ZipArchive();
    if ($zip->open($chemin) !== true) {
        throw new RuntimeException('Fichier illisible. Utilisez un .xlsx ou un .csv valide.');
    }

    $partagees = [];
    $brut = $zip->getFromName('xl/sharedStrings.xml');
    if ($brut !== false) {
        $xml = @simplexml_load_string($brut);
        foreach ($xml->si ?? [] as $si) {
            // Une chaîne peut être découpée en plusieurs fragments (`r`) stylés.
            $partagees[] = $si->t !== null && count($si->r ?? []) === 0
                ? (string) $si->t
                : implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
        }
    }

    // La première feuille n'est pas toujours `sheet1.xml` : on prend la première
    // entrée effectivement présente dans l'archive.
    $feuille = false;
    for ($i = 1; $i <= 20 && $feuille === false; $i++) {
        $feuille = $zip->getFromName("xl/worksheets/sheet$i.xml");
    }
    $zip->close();

    if ($feuille === false) {
        throw new RuntimeException('Aucune feuille de calcul trouvée dans le fichier.');
    }

    $xml = @simplexml_load_string($feuille);
    if ($xml === false) throw new RuntimeException('Fichier illisible.');

    $lignes = [];
    foreach ($xml->sheetData->row ?? [] as $rangee) {
        $cellules = [];
        foreach ($rangee->c ?? [] as $c) {
            $reference = (string) $c['r'];
            $colonne = colonneXlsx(preg_replace('/\d+/', '', $reference) ?: 'A');
            $valeur = (string) $c->v;

            if ((string) $c['t'] === 's') {
                $valeur = $partagees[(int) $valeur] ?? '';
            } elseif ((string) $c['t'] === 'inlineStr') {
                $valeur = (string) $c->is->t;
            }

            $cellules[$colonne] = $valeur;
        }
        if (!$cellules) { $lignes[] = []; continue; }

        // Colonnes manquantes comblées : les index restent alignés d'une ligne à l'autre.
        $maxi = max(array_keys($cellules));
        $ligne = [];
        for ($i = 0; $i <= $maxi; $i++) $ligne[] = $cellules[$i] ?? '';
        $lignes[] = $ligne;
    }

    return $lignes;
}

/** « A » → 0, « B » → 1, « AA » → 26. */
function colonneXlsx(string $lettres): int
{
    $index = 0;
    foreach (str_split(strtoupper($lettres)) as $lettre) {
        $index = $index * 26 + (ord($lettre) - 64);
    }
    return max(0, $index - 1);
}

/**
 * Envoie un CSV en téléchargement (BOM UTF-8 pour qu'Excel lise les accents).
 * Le point-virgule est le séparateur attendu par Excel en configuration française.
 */
function envoyerCsv(string $nomFichier, array $entetes, array $lignes): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');

    $sortie = fopen('php://output', 'w');
    fwrite($sortie, "\xEF\xBB\xBF");
    fputcsv($sortie, $entetes, ';', '"', '\\');
    foreach ($lignes as $ligne) fputcsv($sortie, $ligne, ';', '"', '\\');
    fclose($sortie);
    exit;
}
