<?php
/**
 * Opérations sur les documents commerciaux : création, modification,
 * conversion, regroupement, règlements, annulation.
 * Port de `src/app/(app)/documents/actions.ts`.
 *
 * Chaque opération renvoie un message d'erreur (chaîne) ou null / l'identifiant
 * créé. Les écritures passent toutes par une transaction : un incident laisse la
 * base dans son état d'origine.
 */

/** Une facture ou un avoir est une pièce comptable, pas un brouillon. */
function estPieceComptable(array $document): bool
{
    return in_array($document['type'], ['FACTURE', 'AVOIR'], true);
}

/**
 * Raison pour laquelle une pièce ne se modifie plus, ou null si elle se modifie.
 *
 * Une facture porte un numéro d'une séquence qui ne se troue pas : la réécrire
 * après coup revient à changer un document que le client détient déjà. Deux
 * signaux disent qu'elle est sortie : elle a été réglée, ou elle a été
 * transmise à l'administration fiscale.
 *
 * Le jour où la transmission à la DGI entre en service, la règle se resserre
 * d'elle-même par ce second signal ; ce sera aussi le moment de la durcir à
 * *toute* facture émise, réglée ou non — une ligne à changer ici.
 */
function raisonPieceFigee(array $document): ?string
{
    if (!estPieceComptable($document)) return null;

    $libelle = $document['type'] === 'AVOIR' ? 'Cet avoir' : 'Cette facture';

    if (num($document['montant_paye']) > 0) {
        return "$libelle porte un règlement : elle n'est plus modifiable. "
            . "Supprimez d'abord le règlement, ou établissez un avoir.";
    }

    if (documentTransmis($document['id'])) {
        return "$libelle a été transmise à l'administration fiscale : elle n'est "
            . "plus modifiable. La correction passe par un avoir.";
    }

    return null;
}

/**
 * La pièce a-t-elle été transmise à la plateforme fiscale ?
 *
 * La table `efacture` n'existe pas encore : tant qu'elle manque, la réponse est
 * non partout. Le jour où le module de transmission la pose, le verrou prend
 * effet sans qu'une ligne d'ici ne change.
 */
function documentTransmis(string $documentId): bool
{
    static $disponible = [];
    $base = baseActive();

    if (!isset($disponible[$base])) {
        try {
            $disponible[$base] = tableExiste('efacture');
        } catch (Throwable $e) {
            $disponible[$base] = false;
        }
    }

    if (!$disponible[$base]) return false;

    return (int) valeur("SELECT COUNT(*) FROM efacture
                          WHERE document_id = ? AND etat IN ('ENVOYEE','VALIDEE')",
        [$documentId]) > 0;
}

/** Lignes d'un document, telles que reçues du formulaire (champ JSON `lignes`). */
function lireLignesSaisies(string $json): array
{
    $brut = json_decode($json ?: '[]', true);
    if (!is_array($brut)) return [];

    $lignes = [];
    foreach ($brut as $ligne) {
        $designation = trim((string) ($ligne['designation'] ?? ''));
        $quantite = num($ligne['quantite'] ?? 0);
        if ($designation === '' || $quantite <= 0) continue;

        $lignes[] = [
            'article_id'    => ouNull($ligne['article_id'] ?? null),
            'variante_id'   => ouNull($ligne['variante_id'] ?? null),
            'designation'   => $designation,
            'quantite'      => $quantite,
            'prix_unitaire' => max(0, num($ligne['prix_unitaire'] ?? 0)),
            'remise'        => min(100, max(0, num($ligne['remise'] ?? 0))),
            'tva'           => min(100, max(0, num($ligne['tva'] ?? 0))),
        ];
    }
    return $lignes;
}

/** Écrit les lignes d'un document (après calcul des totaux). */
function ecrireLignes(string $documentId, array $lignesCalculees): void
{
    $aInserer = [];
    foreach ($lignesCalculees as $ordre => $ligne) {
        $aInserer[] = [
            'document_id'   => $documentId,
            'article_id'    => $ligne['article_id'] ?: null,
            'variante_id'   => $ligne['variante_id'] ?: null,
            'designation'   => $ligne['designation'],
            'quantite'      => $ligne['quantite'],
            'prix_unitaire' => $ligne['prix_unitaire'],
            'remise'        => $ligne['remise'],
            'tva'           => $ligne['tva'],
            'total_ht'      => $ligne['total_ht'],
            'total_ttc'     => $ligne['total_ttc'],
            'ordre'         => $ordre,
        ];
    }
    insererLot('ligne_document', $aInserer);
}

/** Mouvements de stock d'un document, dans le sens de son type. */
function mouvementsDocument(array $meta, array $lignes, string $lieuId, string $documentId, string $numero, ?string $userId): void
{
    if (!$meta['affecte_stock'] || !$meta['mouvement']) return;

    appliquerMouvements(
        array_map(fn($ligne) => [
            'article_id'  => $ligne['article_id'] ?: null,
            'variante_id' => $ligne['variante_id'] ?: null,
            'quantite'    => $meta['signe_stock'] * num($ligne['quantite']),
        ], $lignes),
        [
            'lieu_id'     => $lieuId,
            'type'        => $meta['mouvement'],
            'document_id' => $documentId,
            'reference'   => $numero,
            'user_id'     => $userId,
        ],
    );
}

/**
 * Crée un document. Renvoie `['id' => …]` ou `['erreur' => …]`.
 */
function creerDocument(array $session, string $type, array $entete, array $lignes): array
{
    if (!$lignes) return ['erreur' => 'Ajoutez au moins une ligne'];

    $meta = meta($type);
    $totaux = calculerTotaux($lignes, num($entete['remise'] ?? 0), num($entete['frais_port'] ?? 0));
    $paye = $meta['payable'] ? min(num($entete['montant_paye'] ?? 0), $totaux['total_ttc']) : 0;

    // On ne peut retourner que ce qui a réellement été reçu ou livré.
    $refus = verifierRetour($type, ouNull($entete['tiers_id'] ?? null), $session['lieu_id'], $lignes);
    if ($refus) return ['erreur' => $refus];

    $id = transaction(function () use ($session, $type, $entete, $totaux, $meta, $paye) {
        $numero = numeroDocument($type);

        $documentId = inserer('document', [
            'numero'          => $numero,
            'type'            => $type,
            'source'          => $entete['source'] ?? 'BACK_OFFICE',
            'date'            => horodatage($entete['date'] ?? null) ?? maintenant(),
            'date_echeance'   => horodatage($entete['date_echeance'] ?? null),
            'tiers_id'        => ouNull($entete['tiers_id'] ?? null),
            'lieu_id'         => $session['lieu_id'],
            'user_id'         => $session['user_id'],
            'reference'       => ouNull($entete['reference'] ?? null),
            'notes'           => ouNull($entete['notes'] ?? null),
            'total_ht'        => $totaux['total_ht'],
            'remise'          => $totaux['remise'],
            'total_tva'       => $totaux['total_tva'],
            'frais_port'      => $totaux['frais_port'],
            'total_ttc'       => $totaux['total_ttc'],
            'montant_paye'    => $paye,
            'statut_paiement' => $meta['payable'] ? statutDePaiement($paye, $totaux['total_ttc']) : 'IMPAYE',
        ]);

        ecrireLignes($documentId, $totaux['lignes']);
        mouvementsDocument($meta, $totaux['lignes'], $session['lieu_id'], $documentId, $numero, $session['user_id']);

        if ($paye > 0) {
            inserer('paiement', [
                'document_id' => $documentId,
                'date'        => maintenant(),
                'montant'     => $paye,
                'mode'        => $entete['mode_paiement'] ?? 'ESPECES',
                'user_id'     => $session['user_id'],
            ]);
        }

        journaliserEvenement('document', $documentId, 'creation', null,
            un('SELECT * FROM document WHERE id = ?', [$documentId]), $session);

        return $documentId;
    });

    return ['id' => $id];
}

/** Modifie un document existant. */
function modifierDocument(array $session, string $id, array $entete, array $lignes): array
{
    if (!$lignes) return ['erreur' => 'Ajoutez au moins une ligne'];

    $existant = un('SELECT * FROM document WHERE id = ?', [$id]);
    if (!$existant) return ['erreur' => 'Document introuvable.'];
    if ($existant['statut'] === 'CONVERTI') {
        return ['erreur' => "Ce document a été converti, il n'est plus modifiable."];
    }
    // Ses mouvements de stock ont déjà été repris : les réappliquer ici
    // décrémenterait le stock une seconde fois pour un document sans effet.
    if ($existant['statut'] === 'ANNULE') {
        return ['erreur' => "Ce document est annulé, il n'est plus modifiable."];
    }
    $fige = raisonPieceFigee($existant);
    if ($fige) return ['erreur' => $fige];

    $meta = meta($existant['type']);
    $totaux = calculerTotaux($lignes, num($entete['remise'] ?? 0), num($entete['frais_port'] ?? 0));

    // Un total ramené sous le montant déjà réglé laisserait le document avec
    // `montant_paye > total_ttc` : reste à payer négatif et rapports faussés.
    $dejaPaye = num($existant['montant_paye']);
    if ($meta['payable'] && $dejaPaye > $totaux['total_ttc'] + 0.005) {
        return ['erreur' => 'Le nouveau total (' . montant($totaux['total_ttc'], devise())
            . ') est inférieur aux règlements déjà enregistrés (' . montant($dejaPaye, devise())
            . "). Supprimez ou réduisez d'abord les règlements, ou créez un avoir."];
    }

    // Le document en cours est exclu du décompte : ses propres lignes ne doivent
    // pas compter comme déjà retournées.
    $refus = verifierRetour(
        $existant['type'],
        ouNull($entete['tiers_id'] ?? null) ?? $existant['tiers_id'],
        $existant['lieu_id'],
        $lignes,
        null,
        $id,
    );
    if ($refus) return ['erreur' => $refus];

    transaction(function () use ($id, $existant, $entete, $totaux, $meta, $dejaPaye, $session) {
        annulerMouvementsDocument($id);
        q('DELETE FROM ligne_document WHERE document_id = ?', [$id]);

        modifier('document', $id, [
            'date'            => horodatage($entete['date'] ?? null) ?? $existant['date'],
            'date_echeance'   => horodatage($entete['date_echeance'] ?? null),
            'tiers_id'        => ouNull($entete['tiers_id'] ?? null),
            'reference'       => ouNull($entete['reference'] ?? null),
            'notes'           => ouNull($entete['notes'] ?? null),
            'total_ht'        => $totaux['total_ht'],
            'remise'          => $totaux['remise'],
            'total_tva'       => $totaux['total_tva'],
            'frais_port'      => $totaux['frais_port'],
            'total_ttc'       => $totaux['total_ttc'],
            'statut_paiement' => $meta['payable']
                ? statutDePaiement($dejaPaye, $totaux['total_ttc'])
                : $existant['statut_paiement'],
        ]);

        ecrireLignes($id, $totaux['lignes']);
        mouvementsDocument($meta, $totaux['lignes'], $existant['lieu_id'], $id, $existant['numero'], $session['user_id']);

        journaliserEvenement('document', $id, 'modification', $existant,
            un('SELECT * FROM document WHERE id = ?', [$id]), $session);
    });

    return ['id' => $id];
}

/** Convertit un document vers un autre type (devis → facture…). */
function convertirDocument(array $session, string $id, string $cible): array
{
    $source = un('SELECT * FROM document WHERE id = ?', [$id]);
    if (!$source) return ['erreur' => 'Document introuvable.'];
    if (!in_array($cible, meta($source['type'])['conversions'], true)) {
        return ['erreur' => 'Conversion impossible depuis ce document.'];
    }

    $lignes = tous('SELECT * FROM ligne_document WHERE document_id = ? ORDER BY ordre', [$id]);
    $meta = meta($cible);

    $nouveau = transaction(function () use ($source, $lignes, $cible, $meta, $session, $id) {
        $numero = numeroDocument($cible);

        $documentId = inserer('document', [
            'numero'        => $numero,
            'type'          => $cible,
            'date'          => maintenant(),
            'tiers_id'      => $source['tiers_id'],
            'lieu_id'       => $source['lieu_id'],
            'user_id'       => $session['user_id'],
            'parent_id'     => $source['id'],
            'reference'     => $source['numero'],
            'notes'         => $source['notes'],
            'total_ht'      => $source['total_ht'],
            'remise'        => $source['remise'],
            'total_tva'     => $source['total_tva'],
            'frais_port'    => $source['frais_port'],
            'total_ttc'     => $source['total_ttc'],
        ]);

        $aInserer = [];
        foreach ($lignes as $ligne) {
            $aInserer[] = [
                'document_id'   => $documentId,
                'article_id'    => $ligne['article_id'],
                'variante_id'   => $ligne['variante_id'],
                'designation'   => $ligne['designation'],
                'quantite'      => $ligne['quantite'],
                'prix_unitaire' => $ligne['prix_unitaire'],
                'remise'        => $ligne['remise'],
                'tva'           => $ligne['tva'],
                'total_ht'      => $ligne['total_ht'],
                'total_ttc'     => $ligne['total_ttc'],
                'ordre'         => $ligne['ordre'],
            ];
        }
        insererLot('ligne_document', $aInserer);

        if (conversionMouvementeStock($source['type'], $cible) && $meta['mouvement']) {
            mouvementsDocument($meta, $lignes, $source['lieu_id'], $documentId, $numero, $session['user_id']);
        }

        // Un bon de livraison encaissé au comptoir transmet son règlement à la
        // facture : sans cela l'argent resterait sur une pièce convertie,
        // invisible dans le suivi des impayés.
        if (meta($source['type'])['payable'] && $meta['payable']) {
            $paye = transfererReglements([$source['id']], $documentId);
            if ($paye > 0) {
                modifier('document', $documentId, [
                    'montant_paye'    => $paye,
                    'statut_paiement' => statutDePaiement($paye, num($source['total_ttc'])),
                ]);
                modifier('document', $source['id'], ['montant_paye' => 0, 'statut_paiement' => 'IMPAYE']);
            }
        }

        modifier('document', $id, ['statut' => 'CONVERTI']);

        return $documentId;
    });

    return ['id' => $nouveau];
}

/**
 * Rassemble plusieurs bons de livraison en une facture unique.
 *
 * Le stock a déjà été sorti par les bons — la facture n'y touche donc pas. Les
 * règlements encaissés au comptoir sont déplacés sur la facture, et les bons
 * passent en « converti » pour ne plus être proposés au regroupement.
 */
function regrouperEnFacture(array $session, array $ids): array
{
    if (!$ids) return ['erreur' => 'Sélectionnez au moins un bon de livraison.'];

    $args = [$session['lieu_id']];
    $bons = tous(
        "SELECT * FROM document
          WHERE lieu_id = ? AND type = 'BON_LIVRAISON' AND statut NOT IN ('CONVERTI', 'ANNULE')
            AND id IN (" . clauseIn($ids, $args) . ')
          ORDER BY date ASC, numero ASC',
        $args,
    );

    if (count($bons) !== count($ids)) {
        return ['erreur' => 'Un des bons sélectionnés a été annulé ou déjà facturé entre-temps. Rechargez la page.'];
    }

    // Une facture ne peut porter qu'un seul client : mélanger deux tiers
    // produirait une pièce inexploitable.
    $tiers = array_unique(array_map(fn($bon) => $bon['tiers_id'] ?? '', $bons));
    if (count($tiers) > 1) {
        return ['erreur' => "Les bons sélectionnés n'appartiennent pas tous au même client."];
    }

    $fraisPort = 0.0;
    foreach ($bons as $i => $bon) {
        $bons[$i]['lignes'] = tous('SELECT * FROM ligne_document WHERE document_id = ?', [$bon['id']]);
        $fraisPort += num($bon['frais_port']);
    }

    $lignes = lignesRegroupees($bons);
    $totaux = calculerTotaux($lignes, 0, $fraisPort);
    $numeros = array_column($bons, 'numero');

    $facture = transaction(function () use ($bons, $totaux, $numeros, $ids, $session) {
        $numero = numeroDocument('FACTURE');

        $factureId = inserer('document', [
            'numero'     => $numero,
            'type'       => 'FACTURE',
            'date'       => maintenant(),
            'tiers_id'   => $bons[0]['tiers_id'],
            'lieu_id'    => $session['lieu_id'],
            'user_id'    => $session['user_id'],
            // Le premier bon sert de parent : la fiche facture affiche ainsi la
            // filiation, et la liste complète reste dans la référence et les notes.
            'parent_id'  => $bons[0]['id'],
            'reference'  => mb_substr(implode(', ', $numeros), 0, 250),
            'notes'      => 'Regroupement de ' . count($bons) . " bon(s) de livraison :\n" . implode("\n", $numeros),
            'total_ht'   => $totaux['total_ht'],
            'remise'     => $totaux['remise'],
            'total_tva'  => $totaux['total_tva'],
            'frais_port' => $totaux['frais_port'],
            'total_ttc'  => $totaux['total_ttc'],
        ]);

        ecrireLignes($factureId, $totaux['lignes']);

        // Aucun mouvement de stock ici : les bons ont déjà sorti la marchandise.
        $paye = transfererReglements($ids, $factureId);

        modifier('document', $factureId, [
            'montant_paye'    => $paye,
            'statut_paiement' => statutDePaiement($paye, $totaux['total_ttc']),
        ]);

        $args = [];
        q("UPDATE document SET statut = 'CONVERTI', montant_paye = 0, statut_paiement = 'IMPAYE'
            WHERE id IN (" . clauseIn($ids, $args) . ')', $args);

        return $factureId;
    });

    return ['id' => $facture];
}

/** Enregistre un règlement sur un document. */
function ajouterPaiement(array $session, string $documentId, array $donnees): array
{
    $montant = num($donnees['montant'] ?? 0);
    if ($montant <= 0) return ['erreur' => 'Saisissez un montant positif.'];

    $document = un('SELECT * FROM document WHERE id = ?', [$documentId]);
    if (!$document) return ['erreur' => 'Document introuvable.'];
    if ($document['statut'] === 'ANNULE') {
        return ['erreur' => "Ce document est annulé, il n'accepte plus de règlement."];
    }

    $reste = num($document['total_ttc']) - num($document['montant_paye']);
    if ($montant > $reste + 0.005) {
        return ['erreur' => 'Le montant dépasse le reste à payer (' . montant($reste, devise()) . ').'];
    }

    transaction(function () use ($documentId, $document, $donnees, $montant, $session) {
        $mode = $donnees['mode'] ?? 'ESPECES';

        $paiementId = inserer('paiement', [
            'document_id' => $documentId,
            'date'        => horodatage($donnees['date'] ?? null) ?? maintenant(),
            'montant'     => $montant,
            'mode'        => $mode,
            'reference'   => ouNull($donnees['reference'] ?? null),
            'note'        => ouNull($donnees['note'] ?? null),
            'user_id'     => $session['user_id'],
        ]);

        // Un règlement par chèque ou effet crée l'effet correspondant à suivre.
        if ($mode === 'CHEQUE' || $mode === 'EFFET') {
            inserer('effet', [
                'type'          => $mode,
                'sens'          => meta($document['type'])['sens'] === 'VENTE' ? 'RECU' : 'EMIS',
                'numero'        => ouNull($donnees['reference'] ?? null) ?? mb_strtoupper(substr($paiementId, -8)),
                'banque'        => ouNull($donnees['banque'] ?? null),
                'montant'       => $montant,
                'date_emission' => maintenant(),
                'date_echeance' => horodatage($donnees['date_echeance'] ?? null) ?? maintenant(),
                'tiers_id'      => $document['tiers_id'],
                'document_id'   => $documentId,
                'paiement_id'   => $paiementId,
            ]);
        }

        $paye = num($document['montant_paye']) + $montant;
        modifier('document', $documentId, [
            'montant_paye'    => $paye,
            'statut_paiement' => statutDePaiement($paye, num($document['total_ttc'])),
        ]);

        journaliserEvenement('paiement', $paiementId, 'encaissement', null,
            un('SELECT * FROM paiement WHERE id = ?', [$paiementId]), $session);
    });

    return [];
}

function supprimerPaiement(string $paiementId, ?array $session = null): void
{
    $paiement = un('SELECT * FROM paiement WHERE id = ?', [$paiementId]);
    if (!$paiement) return;

    $document = un('SELECT * FROM document WHERE id = ?', [$paiement['document_id']]);
    if (!$document) return;

    transaction(function () use ($paiementId, $paiement, $document, $session) {
        q('DELETE FROM effet WHERE paiement_id = ?', [$paiementId]);
        journaliserEvenement('paiement', $paiementId, 'suppression', $paiement, null, $session);
        supprimer('paiement', $paiementId);

        $paye = max(0, num($document['montant_paye']) - num($paiement['montant']));
        modifier('document', $document['id'], [
            'montant_paye'    => $paye,
            'statut_paiement' => statutDePaiement($paye, num($document['total_ttc'])),
        ]);
    });
}

function annulerDocument(string $id, ?array $session = null): void
{
    $avant = un('SELECT * FROM document WHERE id = ?', [$id]);
    if (!$avant) return;

    transaction(function () use ($id, $avant, $session) {
        annulerMouvementsDocument($id);
        modifier('document', $id, ['statut' => 'ANNULE']);
        journaliserEvenement('document', $id, 'annulation', $avant,
            un('SELECT * FROM document WHERE id = ?', [$id]), $session);
    });
}

/**
 * Supprime un document. Renvoie un message d'erreur, ou null s'il est parti.
 *
 * Une facture ou un avoir ne s'efface jamais : le numéro est attribué, la
 * séquence ne se troue pas, et une pièce disparue est indéfendable devant un
 * contrôle. L'annulation, elle, garde la trace — c'est la bonne porte.
 */
function supprimerDocument(string $id, ?array $session = null): ?string
{
    $avant = un('SELECT * FROM document WHERE id = ?', [$id]);
    if (!$avant) return null;

    if (estPieceComptable($avant)) {
        [$libelle, $pronom] = $avant['type'] === 'AVOIR'
            ? ['Un avoir', 'Annulez-le']
            : ['Une facture', 'Annulez-la'];
        return "$libelle ne se supprime pas : son numéro est attribué. "
            . "$pronom plutôt — le document reste au dossier, sans effet.";
    }

    transaction(function () use ($id, $avant, $session) {
        annulerMouvementsDocument($id);
        q('DELETE FROM effet WHERE document_id = ?', [$id]);
        journaliserEvenement('document', $id, 'suppression', $avant, null, $session);
        supprimer('document', $id);
    });

    return null;
}
