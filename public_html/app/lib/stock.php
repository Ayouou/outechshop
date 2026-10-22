<?php
/**
 * Écritures de stock : quantités par emplacement et journal des mouvements.
 * Port de `src/lib/stock.ts`.
 *
 * Un « emplacement » est le triplet article / variante / lieu d'affaires. La
 * table `stock` en porte la quantité courante, `mouvement_stock` en garde
 * l'historique avec le stock résultant de chaque écriture.
 */

/**
 * Somme des lignes de stock d'un emplacement.
 *
 * MySQL traite deux NULL comme distincts : l'index unique ne protège pas le cas
 * « article sans variante », deux lignes peuvent donc coexister. Toute lecture
 * passe par ici pour rester juste malgré un éventuel doublon.
 */
function quantiteStock(?array $lignes): float
{
    $total = 0.0;
    foreach ($lignes ?? [] as $ligne) $total += num($ligne['quantite'] ?? 0);
    return $total;
}

/** Quantité en stock d'un article (ou d'une variante) sur un lieu d'affaires. */
function quantiteDisponible(string $articleId, string $lieuId, ?string $varianteId = null): float
{
    $sql = 'SELECT quantite FROM stock WHERE article_id = ? AND lieu_id = ? AND '
        . ($varianteId === null ? 'variante_id IS NULL' : 'variante_id = ?');
    $params = [$articleId, $lieuId];
    if ($varianteId !== null) $params[] = $varianteId;

    return quantiteStock(tous($sql, $params));
}

/**
 * Applique un mouvement de stock isolé : met à jour l'emplacement et journalise.
 * Pour un document à plusieurs lignes, préférer `appliquerMouvements`.
 */
function appliquerMouvement(array $entree): void
{
    $varianteId = $entree['variante_id'] ?? null;
    $quantite = num($entree['quantite']);

    $sql = 'SELECT id, quantite FROM stock WHERE article_id = ? AND lieu_id = ? AND '
        . ($varianteId === null ? 'variante_id IS NULL' : 'variante_id = ?');
    $params = [$entree['article_id'], $entree['lieu_id']];
    if ($varianteId !== null) $params[] = $varianteId;

    $lignes = tous($sql, $params);
    $stockApres = quantiteStock($lignes) + $quantite;

    if ($lignes) {
        // En cas de doublon on écrit sur la première ligne : la somme reste juste.
        q('UPDATE stock SET quantite = quantite + ?, updated_at = ? WHERE id = ?',
            [$quantite, maintenant(), $lignes[0]['id']]);
    } else {
        inserer('stock', [
            'article_id'  => $entree['article_id'],
            'variante_id' => $varianteId,
            'lieu_id'     => $entree['lieu_id'],
            'quantite'    => $quantite,
            'updated_at'  => maintenant(),
        ]);
    }

    inserer('mouvement_stock', [
        'article_id'      => $entree['article_id'],
        'variante_id'     => $varianteId,
        'lieu_id'         => $entree['lieu_id'],
        'type'            => $entree['type'],
        'quantite'        => $quantite,
        'stock_apres'     => $stockApres,
        'document_id'     => $entree['document_id'] ?? null,
        'consommation_id' => $entree['consommation_id'] ?? null,
        'depense_id'      => $entree['depense_id'] ?? null,
        'reference'       => $entree['reference'] ?? null,
        'note'            => $entree['note'] ?? null,
        'user_id'         => $entree['user_id'] ?? null,
    ]);
}

/**
 * Applique en une fois les mouvements de toutes les lignes d'un document.
 *
 * Les lectures sont groupées, les lignes d'un même emplacement cumulées, et le
 * journal écrit d'un seul coup : un document de trente-cinq articles ne coûte
 * plus une centaine d'allers-retours.
 *
 * `$lignes` : `article_id`, `variante_id`, `quantite` (signée, déjà multipliée
 * par le sens du document). `$commun` : `lieu_id`, `type`, `document_id`,
 * `depense_id`, `reference`, `note`, `user_id`.
 */
function appliquerMouvements(array $lignes, array $commun): void
{
    $avecArticle = array_values(array_filter($lignes, fn($l) => !empty($l['article_id'])));
    if (!$avecArticle) return;

    // Les articles non suivis en stock (gere_stock désactivé) ne bougent pas.
    $ids = array_values(array_unique(array_column($avecArticle, 'article_id')));
    $params = [];
    $suivis = colonne(
        'SELECT id FROM article WHERE gere_stock = 1 AND id IN (' . clauseIn($ids, $params) . ')',
        $params,
    );
    if (!$suivis) return;
    $suivis = array_flip($suivis);

    $aMouvementer = array_values(array_filter($avecArticle, fn($l) => isset($suivis[$l['article_id']])));
    if (!$aMouvementer) return;

    $cle = fn($articleId, $varianteId) => $articleId . '|' . ($varianteId ?? '');

    $params = [$commun['lieu_id']];
    $stocks = tous(
        'SELECT id, article_id, variante_id, quantite FROM stock
         WHERE lieu_id = ? AND article_id IN (' . clauseIn(array_keys($suivis), $params) . ')',
        $params,
    );

    // Plusieurs lignes peuvent coexister pour un emplacement sans variante : on
    // somme pour connaître la quantité réelle, et on écrit sur la première.
    $emplacements = [];
    foreach ($stocks as $stock) {
        $k = $cle($stock['article_id'], $stock['variante_id']);
        if (isset($emplacements[$k])) $emplacements[$k]['quantite'] += num($stock['quantite']);
        else $emplacements[$k] = ['id' => $stock['id'], 'quantite' => num($stock['quantite'])];
    }

    $journal = [];
    $deltas = [];

    foreach ($aMouvementer as $ligne) {
        $varianteId = $ligne['variante_id'] ?? null;
        $k = $cle($ligne['article_id'], $varianteId);
        $quantite = num($ligne['quantite']);
        $cumul = ($deltas[$k] ?? 0) + $quantite;
        $deltas[$k] = $cumul;

        $journal[] = [
            'article_id'  => $ligne['article_id'],
            'variante_id' => $varianteId,
            'lieu_id'     => $commun['lieu_id'],
            'type'        => $commun['type'],
            'quantite'    => $quantite,
            // Le stock tel qu'il sera après cette ligne : départ + cumul des
            // lignes précédentes du même emplacement.
            'stock_apres' => ($emplacements[$k]['quantite'] ?? 0) + $cumul,
            'document_id' => $commun['document_id'] ?? null,
            'depense_id'  => $commun['depense_id'] ?? null,
            'reference'   => $commun['reference'] ?? null,
            'note'        => $commun['note'] ?? null,
            'user_id'     => $commun['user_id'] ?? null,
        ];
    }

    $aCreer = [];
    foreach ($deltas as $k => $delta) {
        if (isset($emplacements[$k])) {
            q('UPDATE stock SET quantite = quantite + ?, updated_at = ? WHERE id = ?',
                [$delta, maintenant(), $emplacements[$k]['id']]);
            continue;
        }
        [$articleId, $varianteId] = explode('|', $k, 2);
        $aCreer[] = [
            'article_id'  => $articleId,
            'variante_id' => $varianteId !== '' ? $varianteId : null,
            'lieu_id'     => $commun['lieu_id'],
            'quantite'    => $delta,
            'updated_at'  => maintenant(),
        ];
    }

    if ($aCreer) insererLot('stock', $aCreer);
    insererLot('mouvement_stock', $journal);
}

/** Annule les mouvements d'un document (suppression, annulation, modification). */
function annulerMouvementsDocument(string $documentId): void
{
    $mouvements = tous(
        'SELECT article_id, variante_id, lieu_id, quantite FROM mouvement_stock WHERE document_id = ?',
        [$documentId],
    );

    restituerMouvements($mouvements);

    q('DELETE FROM mouvement_stock WHERE document_id = ?', [$documentId]);
}

/**
 * Rend au stock ce que des mouvements en avaient retiré (ou l'inverse).
 * Les lignes ne sont pas supprimées : l'appelant s'en charge, car lui seul
 * sait à quoi elles étaient rattachées.
 *
 * `$mouvements` : `article_id`, `variante_id`, `lieu_id`, `quantite`.
 */
function restituerMouvements(array $mouvements): void
{
    if (!$mouvements) return;

    $cle = fn($a, $v, $l) => $a . '|' . ($v ?? '') . '|' . $l;

    // Un document touche souvent le même emplacement plusieurs fois : on cumule
    // pour n'écrire qu'une seule fois par emplacement.
    $deltas = [];
    foreach ($mouvements as $mouvement) {
        $k = $cle($mouvement['article_id'], $mouvement['variante_id'], $mouvement['lieu_id']);
        // Restituer, c'est appliquer l'inverse du mouvement enregistré.
        $inverse = -num($mouvement['quantite']);
        if (isset($deltas[$k])) $deltas[$k]['quantite'] += $inverse;
        else $deltas[$k] = [
            'article_id'  => $mouvement['article_id'],
            'variante_id' => $mouvement['variante_id'],
            'lieu_id'     => $mouvement['lieu_id'],
            'quantite'    => $inverse,
        ];
    }

    $params = [];
    $articles = array_values(array_unique(array_column($mouvements, 'article_id')));
    $lieux = array_values(array_unique(array_column($mouvements, 'lieu_id')));
    $sql = 'SELECT id, article_id, variante_id, lieu_id FROM stock WHERE article_id IN ('
        . clauseIn($articles, $params) . ') AND lieu_id IN (' . clauseIn($lieux, $params) . ')';

    $emplacements = [];
    foreach (tous($sql, $params) as $stock) {
        $k = $cle($stock['article_id'], $stock['variante_id'], $stock['lieu_id']);
        if (!isset($emplacements[$k])) $emplacements[$k] = $stock['id'];
    }

    $aCreer = [];
    foreach ($deltas as $k => $delta) {
        if ($delta['quantite'] == 0) continue;
        if (isset($emplacements[$k])) {
            q('UPDATE stock SET quantite = quantite + ?, updated_at = ? WHERE id = ?',
                [$delta['quantite'], maintenant(), $emplacements[$k]]);
            continue;
        }
        $aCreer[] = $delta + ['updated_at' => maintenant()];
    }

    if ($aCreer) insererLot('stock', $aCreer);
}
