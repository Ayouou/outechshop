<?php
/**
 * Listes : pagination, recherche et construction d'URL conservant les filtres.
 * Port de `src/lib/liste.ts`.
 */

const TAILLES_PAGE = [10, 25, 50, 100];

function lireParams(): array
{
    $page = max(1, (int) param('page', 1));
    $demandee = (int) param('taille', 25) ?: 25;
    $taille = in_array($demandee, TAILLES_PAGE, true) ? $demandee : 25;
    $q = trim((string) param('q', ''));

    return [
        'page'   => $page,
        'taille' => $taille,
        'q'      => $q,
        'skip'   => ($page - 1) * $taille,
        'take'   => $taille,
    ];
}

/** Construit une URL en conservant les paramètres GET existants. */
function urlAvec(string $base, array $modifications = []): string
{
    $params = $_GET;
    foreach ($modifications as $cle => $valeur) {
        if ($valeur === null || $valeur === '') unset($params[$cle]);
        else $params[$cle] = (string) $valeur;
    }
    $query = http_build_query($params);
    return u($base) . ($query ? '?' . $query : '');
}

/**
 * Condition SQL `LIKE` sur plusieurs colonnes.
 *
 * L'interclassement utf8mb4_unicode_ci étant insensible à la casse, « fact »
 * trouve « Facture » sans traitement particulier — c'est ce que faisait
 * `mode: "insensitive"` sous PostgreSQL.
 */
function rechercheSur(array $champs, string $q, array &$params): string
{
    if ($q === '') return '';
    $motif = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $conditions = [];
    foreach ($champs as $champ) {
        $conditions[] = "$champ LIKE ?";
        $params[] = $motif;
    }
    return '(' . implode(' OR ', $conditions) . ')';
}

/** Assemble des fragments de WHERE non vides. */
function assembler(array $conditions, string $liaison = ' AND '): string
{
    $utiles = array_values(array_filter($conditions, fn($c) => trim((string) $c) !== ''));
    return $utiles ? implode($liaison, $utiles) : '1';
}
