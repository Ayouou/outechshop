<?php
/**
 * Retours d'achat et avoirs : ce qu'un tiers a livré (ou reçu) sur une période,
 * diminué de ce qui a déjà été retourné. Port de `src/lib/retours.ts`.
 *
 * Côté vente on s'appuie sur les « ventes réelles » : le client peut retourner
 * ce qui lui a été livré sur un bon comme ce qui lui a été facturé, et un bon
 * déjà regroupé est écarté — sinon la même marchandise, présente sur le bon et
 * sur sa facture, autoriserait un retour du double des quantités.
 */

function periodeParDefaut(): int
{
    return 30;
}

/** Condition SQL des documents servant de base à un retour, ou null. */
function conditionOrigineRetour(string $type): ?string
{
    if ($type === 'RETOUR_ACHAT') return "d.type = 'ACHAT' AND d.statut <> 'ANNULE'";
    if ($type === 'AVOIR') return conditionVentesReelles('d');
    return null;
}

/**
 * Lignes retournables pour un tiers.
 * Les lignes libres (sans article) sont ignorées : elles ne sont pas
 * rattachables de façon fiable d'un document à l'autre.
 */
function lignesRetournables(string $type, string $tiersId, string $lieuId, ?int $jours = null): array
{
    $origine = conditionOrigineRetour($type);
    if (!$origine) return [];

    $jours = $jours ?: periodeParDefaut();
    $depuis = (new DateTime())->modify("-$jours days")->format('Y-m-d') . ' 00:00:00.000';

    $documentsOrigine = tous(
        "SELECT d.id, d.numero, d.date, l.article_id, l.variante_id, l.designation,
                l.quantite, l.prix_unitaire, l.tva
           FROM document d
           JOIN ligne_document l ON l.document_id = d.id
          WHERE d.tiers_id = ? AND d.lieu_id = ? AND d.date >= ?
            AND l.article_id IS NOT NULL AND ($origine)
          ORDER BY d.date ASC, l.ordre ASC",
        [$tiersId, $lieuId, $depuis],
    );

    $documentsRetour = tous(
        "SELECT l.article_id, l.variante_id, l.quantite
           FROM document d
           JOIN ligne_document l ON l.document_id = d.id
          WHERE d.tiers_id = ? AND d.lieu_id = ? AND d.date >= ?
            AND d.type = ? AND d.statut <> 'ANNULE' AND l.article_id IS NOT NULL",
        [$tiersId, $lieuId, $depuis, $type],
    );

    // Quantités déjà retournées, par article/variante.
    $retournees = [];
    foreach ($documentsRetour as $ligne) {
        $cle = cleVente($ligne['article_id'], $ligne['variante_id']);
        $retournees[$cle] = ($retournees[$cle] ?? 0) + num($ligne['quantite']);
    }

    $cumul = [];
    foreach ($documentsOrigine as $ligne) {
        $cle = cleVente($ligne['article_id'], $ligne['variante_id']);

        if (isset($cumul[$cle])) {
            $cumul[$cle]['quantite_origine'] += num($ligne['quantite']);
            // Documents triés par date : le dernier prix vu fait foi.
            $cumul[$cle]['prix_unitaire'] = num($ligne['prix_unitaire']);
            $cumul[$cle]['tva'] = num($ligne['tva']);
            $cumul[$cle]['derniere_date'] = $ligne['date'];
            if (!in_array($ligne['numero'], $cumul[$cle]['documents'], true)) {
                $cumul[$cle]['documents'][] = $ligne['numero'];
            }
            continue;
        }

        $cumul[$cle] = [
            'cle'                => $cle,
            'article_id'         => $ligne['article_id'],
            'variante_id'        => $ligne['variante_id'],
            'designation'        => $ligne['designation'],
            'prix_unitaire'      => num($ligne['prix_unitaire']),
            'tva'                => num($ligne['tva']),
            'quantite_origine'   => num($ligne['quantite']),
            'quantite_retournee' => 0.0,
            'quantite_restante'  => 0.0,
            'documents'          => [$ligne['numero']],
            'derniere_date'      => $ligne['date'],
        ];
    }

    $resultat = [];
    foreach ($cumul as $ligne) {
        $deja = $retournees[$ligne['cle']] ?? 0;
        $ligne['quantite_retournee'] = $deja;
        $ligne['quantite_restante'] = max(0, $ligne['quantite_origine'] - $deja);
        $resultat[] = $ligne;
    }

    usort($resultat, fn($a, $b) => strcmp((string) $b['derniere_date'], (string) $a['derniere_date']));
    return $resultat;
}

/**
 * Vérifie qu'un retour ne dépasse pas ce qui reste retournable.
 * La vérification est serveur : elle ne dépend pas de l'interface.
 * `$documentExclu` neutralise le document en cours de modification.
 */
function verifierRetour(
    string $type,
    ?string $tiersId,
    string $lieuId,
    array $lignes,
    ?int $jours = null,
    ?string $documentExclu = null,
): ?string {
    if (!conditionOrigineRetour($type) || !$tiersId) return null;

    $retournables = [];
    foreach (lignesRetournables($type, $tiersId, $lieuId, $jours) as $ligne) {
        $retournables[$ligne['cle']] = $ligne;
    }

    // Le document modifié compte déjà dans les retours : on le neutralise.
    $dejaComptees = [];
    if ($documentExclu) {
        foreach (tous('SELECT article_id, variante_id, quantite FROM ligne_document
                        WHERE document_id = ? AND article_id IS NOT NULL', [$documentExclu]) as $l) {
            $cle = cleVente($l['article_id'], $l['variante_id']);
            $dejaComptees[$cle] = ($dejaComptees[$cle] ?? 0) + num($l['quantite']);
        }
    }

    foreach ($lignes as $ligne) {
        if (empty($ligne['article_id'])) continue;
        $cle = cleVente($ligne['article_id'], $ligne['variante_id'] ?? null);
        $source = $retournables[$cle] ?? null;
        $credit = $dejaComptees[$cle] ?? 0;

        if (!$source) {
            $nom = valeur('SELECT nom FROM article WHERE id = ?', [$ligne['article_id']]) ?? 'Article inconnu';
            return "« $nom » n'apparaît dans aucun document des "
                . ($jours ?: periodeParDefaut()) . ' derniers jours pour ce tiers.';
        }

        $disponible = $source['quantite_restante'] + $credit;
        if (num($ligne['quantite']) > $disponible + 0.0001) {
            return "Retour impossible pour « {$source['designation']} » : "
                . nombre($ligne['quantite']) . ' demandé(s) pour ' . nombre($disponible)
                . ' restant(s) sur la période.';
        }
    }

    return null;
}
