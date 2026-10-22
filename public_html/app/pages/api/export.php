<?php
/**
 * Exports CSV : tiers, articles, mouvements, documents, effets, dépenses.
 *
 * Le CSV est écrit avec un BOM UTF-8 et le point-virgule comme séparateur,
 * c'est-à-dire ce qu'Excel attend en configuration française.
 */

$session = sessionCourante();
if (!$session) {
    http_response_code(401);
    exit('Non autorisé');
}

$quoi = $params['quoi'];
$lieuId = $session['lieu_id'];
$q = (string) param('q', '');
$args = [];

$tableau = null;

switch ($quoi) {
    case 'tiers':
        $where = assembler([rechercheSur(['t.nom', 't.nom_entreprise', 't.telephone', 't.email', 't.code', 't.ville'], $q, $args)]);
        $lignes = tous(
            "SELECT t.*, g.nom AS groupe_nom FROM tiers t
               LEFT JOIN groupe_client g ON g.id = t.groupe_id
              WHERE $where ORDER BY t.created_at DESC", $args);

        $tableau = [
            'titre'    => 'Tiers',
            'colonnes' => ['Code', 'Type', 'Nom', 'Entreprise', 'Téléphone', 'E-mail', 'Ville', 'ICE', 'Groupe', 'Plafond crédit', 'Actif'],
            'lignes'   => array_map(fn($t) => [
                $t['code'], $t['type'], $t['nom'], $t['nom_entreprise'] ?? '', $t['telephone'] ?? '',
                $t['email'] ?? '', $t['ville'] ?? '', $t['ice'] ?? '', $t['groupe_nom'] ?? '',
                num($t['plafond_credit']), $t['actif'] ? 'Oui' : 'Non',
            ], $lignes),
        ];
        break;

    case 'articles':
        $where = assembler([rechercheSur(['a.nom', 'a.reference', 'a.code_barre', 'a.description'], $q, $args)]);
        $args[] = $lieuId;
        $lignes = tous(
            "SELECT a.*, c.nom AS categorie_nom, m.nom AS marque_nom,
                    (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s WHERE s.article_id = a.id AND s.lieu_id = ?) AS stock
               FROM article a
               LEFT JOIN categorie c ON c.id = a.categorie_id
               LEFT JOIN marque m ON m.id = a.marque_id
              WHERE $where ORDER BY a.nom", $args);

        $tableau = [
            'titre'    => 'Articles',
            'colonnes' => ['Référence', 'Code-barres', 'Nom', 'Catégorie', 'Marque', 'Unité', 'Prix achat', 'Prix vente', 'TVA %', 'Stock', 'Seuil alerte', 'Actif'],
            'lignes'   => array_map(fn($a) => [
                $a['reference'], $a['code_barre'] ?? '', $a['nom'], $a['categorie_nom'] ?? '', $a['marque_nom'] ?? '',
                $a['unite'], num($a['prix_achat']), num($a['prix_vente']), num($a['tva']),
                num($a['stock']), num($a['stock_alerte']), $a['actif'] ? 'Oui' : 'Non',
            ], $lignes),
        ];
        break;

    case 'mouvements':
        $args = [$lieuId];
        $conditions = ['m.lieu_id = ?'];
        $recherche = rechercheSur(['a.nom', 'a.reference'], $q, $args);
        if ($recherche) $conditions[] = $recherche;
        $where = assembler($conditions);

        $lignes = tous(
            "SELECT m.*, a.nom AS article_nom, a.reference, d.numero AS document_numero, us.nom AS user_nom
               FROM mouvement_stock m
               JOIN article a ON a.id = m.article_id
               LEFT JOIN document d ON d.id = m.document_id
               LEFT JOIN utilisateur us ON us.id = m.user_id
              WHERE $where ORDER BY m.created_at DESC LIMIT 5000", $args);

        $tableau = [
            'titre'    => 'Mouvements de stock',
            'colonnes' => ['Date', 'Article', 'Référence', 'Type', 'Document', 'Quantité', 'Stock après', 'Utilisateur'],
            'lignes'   => array_map(fn($m) => [
                dateFr($m['created_at']), $m['article_nom'], $m['reference'],
                TYPES_MOUVEMENT[$m['type']] ?? $m['type'],
                $m['document_numero'] ?? $m['reference'] ?? '', num($m['quantite']), num($m['stock_apres']),
                $m['user_nom'] ?? '',
            ], $lignes),
        ];
        break;

    case 'documents':
        $type = (string) param('type', '');
        if (!isset(DOCUMENTS[$type])) break;

        $args = [$type, $lieuId];
        $conditions = ['d.type = ?', 'd.lieu_id = ?'];
        $recherche = rechercheSur(['d.numero', 'd.reference', 't.nom'], $q, $args);
        if ($recherche) $conditions[] = $recherche;
        $where = assembler($conditions);

        $lignes = tous(
            "SELECT d.*, t.nom AS tiers_nom, us.nom AS user_nom FROM document d
               LEFT JOIN tiers t ON t.id = d.tiers_id
               LEFT JOIN utilisateur us ON us.id = d.user_id
              WHERE $where ORDER BY d.date DESC", $args);

        $tableau = [
            'titre'    => meta($type)['pluriel'],
            'colonnes' => ['Date', 'Numéro', 'Tiers', 'Référence', 'Total HT', 'Remise', 'TVA', 'Total TTC', 'Payé', 'Statut', 'Paiement', 'Créé par'],
            'lignes'   => array_map(fn($d) => [
                dateFr($d['date']), $d['numero'], $d['tiers_nom'] ?? '', $d['reference'] ?? '',
                num($d['total_ht']), num($d['remise']), num($d['total_tva']), num($d['total_ttc']),
                num($d['montant_paye']), $d['statut'], $d['statut_paiement'], $d['user_nom'] ?? '',
            ], $lignes),
        ];
        break;

    case 'effets':
        $where = assembler([rechercheSur(['e.numero', 'e.banque', 't.nom'], $q, $args)]);
        $lignes = tous(
            "SELECT e.*, t.nom AS tiers_nom, d.numero AS document_numero FROM effet e
               LEFT JOIN tiers t ON t.id = e.tiers_id
               LEFT JOIN document d ON d.id = e.document_id
              WHERE $where ORDER BY e.date_echeance ASC", $args);

        $tableau = [
            'titre'    => 'Chèques et effets',
            'colonnes' => ['Type', 'Sens', 'Numéro', 'Tiers', 'Banque', 'Document', 'Émission', 'Échéance', 'Montant', 'Statut'],
            'lignes'   => array_map(fn($e) => [
                $e['type'], $e['sens'], $e['numero'], $e['tiers_nom'] ?? '', $e['banque'] ?? '',
                $e['document_numero'] ?? '', dateFr($e['date_emission']), dateFr($e['date_echeance']),
                num($e['montant']), $e['statut'],
            ], $lignes),
        ];
        break;

    case 'depenses':
        $args = [$lieuId];
        $conditions = ['d.lieu_id = ?'];
        $recherche = rechercheSur(['d.numero', 'd.designation', 'd.beneficiaire'], $q, $args);
        if ($recherche) $conditions[] = $recherche;
        $where = assembler($conditions);

        $lignes = tous(
            "SELECT d.*, c.nom AS categorie_nom FROM depense d
               LEFT JOIN categorie_depense c ON c.id = d.categorie_id
              WHERE $where ORDER BY d.date DESC", $args);

        $tableau = [
            'titre'    => 'Dépenses',
            // La nature ouvre le tableau : sans elle, additionner la colonne
            // « Montant TTC » compterait le papier une fois à l'achat et une fois
            // à la consommation.
            'colonnes' => ['Nature', 'Date', 'Numéro', 'Désignation', 'Catégorie', 'Bénéficiaire', 'Mode', 'Montant HT', 'TVA %', 'Montant TTC', 'Payé', 'Statut'],
            'lignes'   => array_map(function ($d) {
                $estConso = ($d['origine'] ?? 'SAISIE') === 'CONSOMMATION';
                return [
                    $estConso ? 'Stock consommé' : 'Dépense saisie',
                    dateFr($d['date']), $d['numero'], $d['designation'], $d['categorie_nom'] ?? '',
                    $d['beneficiaire'] ?? '',
                    // Une consommation n'a pas de mode de règlement : laisser celui de la
                    // colonne (« Espèces » par défaut) rangerait 884 dhs dans un mode où
                    // aucun billet n'est passé.
                    $estConso ? '' : (MODES_PAIEMENT[$d['mode']] ?? $d['mode']),
                    num($d['montant_ht']), num($d['tva']), num($d['montant_ttc']),
                    num($d['montant_paye']), $d['statut_paiement'],
                ];
            }, $lignes),
        ];
        break;
}

if (!$tableau) {
    http_response_code(404);
    exit('Export inconnu');
}

envoyerCsv('outech-' . $quoi . '-' . date('Y-m-d') . '.csv', $tableau['colonnes'], $tableau['lignes']);
