<?php
/**
 * Passage des bons de livraison à la facture. Port de `src/lib/facturation.ts`.
 *
 * La caisse remet un bon de livraison encaissé au comptoir ; la facture arrive
 * plus tard et peut regrouper plusieurs bons. Deux règles délicates : reprendre
 * les lignes sans fausser les totaux, et déplacer les règlements pour que
 * l'argent ne soit compté qu'une fois.
 */

/**
 * Aplatit les lignes de plusieurs documents en une seule liste facturable.
 *
 * La remise globale d'un document (un montant en dirhams, réparti au prorata)
 * n'a plus de sens une fois les lignes mélangées : on la convertit en remise de
 * ligne, ce qui conserve exactement chaque base HT et la ventilation de TVA.
 *
 * `$documents` : lignes de `document`, chacune avec sa clé `lignes`.
 */
function lignesRegroupees(array $documents): array
{
    $lignes = [];

    foreach ($documents as $document) {
        $totalHT = num($document['total_ht']);
        $remise = num($document['remise']);
        $facteur = $totalHT > 0 ? ($totalHT - $remise) / $totalHT : 1;

        $siennes = $document['lignes'] ?? [];
        usort($siennes, fn($a, $b) => (int) $a['ordre'] <=> (int) $b['ordre']);

        foreach ($siennes as $ligne) {
            $brut = num($ligne['quantite']) * num($ligne['prix_unitaire']);
            $effectifHT = num($ligne['total_ht']) * $facteur;

            $lignes[] = [
                'article_id'    => $ligne['article_id'],
                'variante_id'   => $ligne['variante_id'],
                'designation'   => $ligne['designation'],
                'quantite'      => num($ligne['quantite']),
                'prix_unitaire' => num($ligne['prix_unitaire']),
                // Sans remise globale, la remise de ligne est reprise telle
                // quelle et le total est reproduit au centime près.
                'remise'        => $remise > 0 && $brut > 0 ? (1 - $effectifHT / $brut) * 100 : num($ligne['remise']),
                'tva'           => num($ligne['tva']),
            ];
        }
    }

    return $lignes;
}

/**
 * Rattache à la facture les règlements portés par les documents d'origine.
 *
 * Les paiements sont déplacés, pas recopiés : après l'opération un seul
 * document porte l'encaissement, à sa date d'origine — la recette reste
 * imputée au jour où elle est réellement entrée en caisse. Renvoie le total
 * déplacé.
 */
function transfererReglements(array $sourceIds, string $factureId): float
{
    if (!$sourceIds) return 0;

    $params = [];
    $paiements = tous(
        'SELECT id, montant FROM paiement WHERE document_id IN (' . clauseIn($sourceIds, $params) . ')',
        $params,
    );
    if (!$paiements) return 0;

    $params = [$factureId];
    q('UPDATE paiement SET document_id = ? WHERE document_id IN (' . clauseIn($sourceIds, $params) . ')', $params);

    // Les chèques et effets suivent leur règlement, sinon le suivi pointerait
    // vers un bon de livraison qui ne porte plus rien.
    $params = [$factureId];
    q('UPDATE effet SET document_id = ? WHERE paiement_id IN ('
        . clauseIn(array_column($paiements, 'id'), $params) . ')', $params);

    $total = 0.0;
    foreach ($paiements as $paiement) $total += num($paiement['montant']);
    return $total;
}
