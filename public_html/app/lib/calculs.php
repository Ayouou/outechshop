<?php
/**
 * Totaux d'un document. Port fidèle de `src/lib/calculs.ts` — c'est le cœur
 * comptable de l'application, les arrondis y sont volontairement au centime.
 */

/**
 * Calcule les totaux d'un document.
 * La remise globale (en dirhams) est répartie au prorata des lignes avant TVA.
 *
 * `$lignes` : tableaux avec `quantite`, `prix_unitaire`, `remise` (%), `tva` (%).
 */
function calculerTotaux(array $lignes, float $remiseGlobale = 0, float $fraisPort = 0): array
{
    $detaillees = [];
    foreach ($lignes as $ligne) {
        $brut = num($ligne['quantite'] ?? 0) * num($ligne['prix_unitaire'] ?? 0);
        $totalHT = arrondir($brut * (1 - num($ligne['remise'] ?? 0) / 100));
        $detaillees[] = $ligne + [
            'total_ht'  => $totalHT,
            'total_ttc' => arrondir($totalHT * (1 + num($ligne['tva'] ?? 0) / 100)),
        ];
    }

    $totalHT = 0.0;
    foreach ($detaillees as $ligne) $totalHT += $ligne['total_ht'];
    $totalHT = arrondir($totalHT);

    $remise = min(arrondir($remiseGlobale), $totalHT);
    $baseHT = arrondir($totalHT - $remise);
    $facteur = $totalHT > 0 ? $baseHT / $totalHT : 1;

    $totalTVA = 0.0;
    foreach ($detaillees as $ligne) {
        $totalTVA += $ligne['total_ht'] * $facteur * (num($ligne['tva'] ?? 0) / 100);
    }
    $totalTVA = arrondir($totalTVA);

    return [
        'lignes'     => $detaillees,
        'total_ht'   => $totalHT,
        'remise'     => $remise,
        'base_ht'    => $baseHT,
        'total_tva'  => $totalTVA,
        'frais_port' => $fraisPort,
        'total_ttc'  => arrondir($baseHT + $totalTVA + $fraisPort),
        'facteur'    => $facteur,
    ];
}

/** Ventilation de la TVA par taux, pour le pied de facture. */
function ventilationTVA(array $lignes, float $facteur = 1): array
{
    $parTaux = [];

    foreach ($lignes as $ligne) {
        $taux = (string) num($ligne['tva'] ?? 0);
        $base = arrondir(num($ligne['total_ht'] ?? 0) * $facteur);
        $courant = $parTaux[$taux] ?? ['base' => 0.0, 'montant' => 0.0];
        $parTaux[$taux] = [
            'base'    => arrondir($courant['base'] + $base),
            'montant' => arrondir($courant['montant'] + $base * (num($taux) / 100)),
        ];
    }

    $resultat = [];
    foreach ($parTaux as $taux => $valeurs) {
        $resultat[] = ['taux' => num($taux)] + $valeurs;
    }
    usort($resultat, fn($a, $b) => $a['taux'] <=> $b['taux']);

    return $resultat;
}

/** Statut de paiement déduit du montant réglé. */
function statutDePaiement(float $paye, float $total): string
{
    if ($paye <= 0) return 'IMPAYE';
    if ($paye + 0.005 >= $total) return 'PAYE';
    return 'PARTIEL';
}
