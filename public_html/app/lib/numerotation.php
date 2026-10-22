<?php
/**
 * Compteurs annuels : `FA2026/0001`, `DV2026/0001`, `BC2026/0001`…
 * Port de `src/lib/numerotation.ts`.
 */

const PREFIXE_DOCUMENT = [
    'DEVIS'           => 'DV',
    'COMMANDE_CLIENT' => 'CC',
    'BON_LIVRAISON'   => 'BL',
    'FACTURE'         => 'FA',
    'AVOIR'           => 'AV',
    'BON_COMMANDE'    => 'BC',
    'ACHAT'           => 'AC',
    'RETOUR_ACHAT'    => 'RA',
];

/**
 * Incrémente un compteur et renvoie le numéro formaté.
 *
 * Les pièces hors documents commerciaux ont leur propre préfixe et appellent
 * directement cette fonction : `DP` pour les dépenses, `OT` pour les ordres de
 * travail de l'atelier.
 *
 * `INSERT … ON DUPLICATE KEY UPDATE` fait l'incrément côté serveur : deux
 * caisses qui facturent en même temps ne peuvent pas obtenir le même numéro.
 * À appeler dans la même transaction que la création du document.
 */
function numeroSuivant(string $prefixe): string
{
    $annee = (int) date('Y');
    $cle = "$prefixe-$annee";

    q('INSERT INTO sequence (cle, dernier) VALUES (?, 1)
       ON DUPLICATE KEY UPDATE dernier = dernier + 1', [$cle]);

    $dernier = (int) valeur('SELECT dernier FROM sequence WHERE cle = ?', [$cle]);

    return $prefixe . $annee . '/' . str_pad((string) $dernier, 4, '0', STR_PAD_LEFT);
}

function numeroDocument(string $type): string
{
    return numeroSuivant(PREFIXE_DOCUMENT[$type] ?? 'DO');
}
