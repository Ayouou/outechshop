<?php
/** Écritures du suivi de livraison, partagées par la liste et la fiche. */

/**
 * Répercute l'avancement logistique sur le statut commercial du document.
 * Un bon converti en facture ou annulé garde son statut : le suivi ne doit pas
 * le rendre à nouveau convertible ni le ressusciter.
 */
function refleterStatutSurDocument(string $documentId, string $statut): void
{
    $document = un('SELECT statut FROM document WHERE id = ?', [$documentId]);
    if (!$document) return;
    if ($document['statut'] === 'CONVERTI' || $document['statut'] === 'ANNULE') return;

    modifier('document', $documentId, ['statut' => $statut === 'LIVRE' ? 'LIVRE' : 'VALIDE']);
}

/**
 * Crée ou met à jour le suivi logistique d'un bon de livraison.
 * Les dates d'expédition et de livraison sont renseignées automatiquement
 * quand le statut avance et qu'elles n'ont pas été saisies.
 */
function enregistrerLivraison(string $documentId, array $donnees): void
{
    $existante = un('SELECT * FROM livraison WHERE document_id = ?', [$documentId]);
    $statut = $donnees['statut'];

    $expedition = horodatage($donnees['date_expedition'] ?? null) ?? ($existante['date_expedition'] ?? null);
    $livraison = horodatage($donnees['date_livraison'] ?? null) ?? ($existante['date_livraison'] ?? null);

    if (!$expedition && ($statut === 'EXPEDIE' || $statut === 'LIVRE')) $expedition = maintenant();
    if (!$livraison && $statut === 'LIVRE') $livraison = maintenant();

    $valeurs = [
        'statut'          => $statut,
        'transporteur'    => ouNull($donnees['transporteur'] ?? null),
        'numero_suivi'    => ouNull($donnees['numero_suivi'] ?? null),
        'adresse'         => ouNull($donnees['adresse'] ?? null),
        'contact'         => ouNull($donnees['contact'] ?? null),
        'frais_livraison' => num($donnees['frais_livraison'] ?? 0),
        'date_expedition' => $expedition,
        'date_livraison'  => $livraison,
        'note'            => ouNull($donnees['note'] ?? null),
    ];

    if ($existante) modifier('livraison', $existante['id'], $valeurs);
    else inserer('livraison', $valeurs + ['document_id' => $documentId]);

    refleterStatutSurDocument($documentId, $statut);
}
