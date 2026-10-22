<?php
/**
 * Métadonnées des documents commerciaux : libellés, chemins, effet sur le
 * stock, conversions possibles. Port de `src/lib/documents.ts`.
 */

/**
 * Chaque type porte :
 *  - `chemin`        segment d'URL sous /ventes ou /achats
 *  - `affecte_stock` le document met-il à jour le stock à la validation ?
 *  - `signe_stock`   sens du mouvement (+ entrée, − sortie)
 *  - `payable`       génère-t-il une créance / dette réglable ?
 *  - `conversions`   pièces que l'on peut en tirer
 */
const DOCUMENTS = [
    'DEVIS' => [
        'label' => 'Devis', 'pluriel' => 'Devis',
        'titre_nouveau' => 'Nouveau devis', 'libelle_creer' => 'Créer le devis',
        'avec_article' => 'le devis', 'chemin' => 'devis', 'sens' => 'VENTE',
        'affecte_stock' => false, 'signe_stock' => 0, 'payable' => false,
        'conversions' => ['COMMANDE_CLIENT', 'BON_LIVRAISON', 'FACTURE'], 'mouvement' => null,
    ],
    'COMMANDE_CLIENT' => [
        'label' => 'Commande client', 'pluriel' => 'Commandes client',
        'titre_nouveau' => 'Nouvelle commande client', 'libelle_creer' => 'Créer la commande client',
        'avec_article' => 'la commande client', 'chemin' => 'commandes', 'sens' => 'VENTE',
        'affecte_stock' => false, 'signe_stock' => 0, 'payable' => false,
        'conversions' => ['BON_LIVRAISON', 'FACTURE'], 'mouvement' => null,
    ],
    'BON_LIVRAISON' => [
        'label' => 'Bon de livraison', 'pluriel' => 'Bons de livraison',
        'titre_nouveau' => 'Nouveau bon de livraison', 'libelle_creer' => 'Créer le bon de livraison',
        'avec_article' => 'le bon de livraison', 'chemin' => 'bons-livraison', 'sens' => 'VENTE',
        'affecte_stock' => true, 'signe_stock' => -1,
        // La caisse encaisse au comptoir et remet un bon de livraison : le
        // règlement doit pouvoir être porté par le bon, pas seulement par la
        // facture qui le regroupera plus tard.
        'payable' => true,
        'conversions' => ['FACTURE'], 'mouvement' => 'VENTE',
    ],
    'FACTURE' => [
        'label' => 'Facture', 'pluriel' => 'Factures',
        'titre_nouveau' => 'Nouvelle facture', 'libelle_creer' => 'Créer la facture',
        'avec_article' => 'la facture', 'chemin' => 'factures', 'sens' => 'VENTE',
        'affecte_stock' => true, 'signe_stock' => -1, 'payable' => true,
        'conversions' => ['AVOIR'], 'mouvement' => 'VENTE',
    ],
    'AVOIR' => [
        'label' => 'Avoir / Retour client', 'pluriel' => 'Avoirs & retours',
        'titre_nouveau' => 'Nouvel avoir', 'libelle_creer' => "Créer l'avoir",
        'avec_article' => "l'avoir", 'chemin' => 'avoirs', 'sens' => 'VENTE',
        'affecte_stock' => true, 'signe_stock' => 1, 'payable' => false,
        'conversions' => [], 'mouvement' => 'RETOUR_CLIENT',
    ],
    'BON_COMMANDE' => [
        'label' => 'Bon de commande', 'pluriel' => 'Bons de commande',
        'titre_nouveau' => 'Nouveau bon de commande', 'libelle_creer' => 'Créer le bon de commande',
        'avec_article' => 'le bon de commande', 'chemin' => 'bons-commande', 'sens' => 'ACHAT',
        'affecte_stock' => false, 'signe_stock' => 0, 'payable' => false,
        'conversions' => ['ACHAT'], 'mouvement' => null,
    ],
    'ACHAT' => [
        'label' => 'Achat', 'pluriel' => 'Achats',
        'titre_nouveau' => 'Nouvel achat', 'libelle_creer' => "Créer l'achat",
        'avec_article' => "l'achat", 'chemin' => 'liste', 'sens' => 'ACHAT',
        'affecte_stock' => true, 'signe_stock' => 1, 'payable' => true,
        'conversions' => ['RETOUR_ACHAT'], 'mouvement' => 'ACHAT',
    ],
    'RETOUR_ACHAT' => [
        'label' => "Retour d'achat", 'pluriel' => "Retours d'achat",
        'titre_nouveau' => "Nouveau retour d'achat", 'libelle_creer' => "Créer le retour d'achat",
        'avec_article' => "le retour d'achat", 'chemin' => 'retours', 'sens' => 'ACHAT',
        'affecte_stock' => true, 'signe_stock' => -1, 'payable' => false,
        'conversions' => [], 'mouvement' => 'RETOUR_FOURNISSEUR',
    ],
];

function meta(string $type): array
{
    return DOCUMENTS[$type] ?? DOCUMENTS['FACTURE'];
}

/** Le document reprend-il des marchandises déjà mouvementées ? */
function estRetour(string $type): bool
{
    return $type === 'RETOUR_ACHAT' || $type === 'AVOIR';
}

/**
 * Une conversion doit-elle générer des mouvements de stock ?
 *
 * Les marchandises ne sortent qu'une fois. Un devis ou une commande n'ayant
 * rien mouvementé, la pièce qui en découle le fait ; mais un bon de livraison
 * a déjà sorti le stock, et la facture qui le reprend ne doit surtout pas le
 * sortir une seconde fois.
 */
function conversionMouvementeStock(string $source, string $cible): bool
{
    return meta($cible)['affecte_stock'] && !meta($source)['affecte_stock'];
}

/**
 * Formats de sortie papier : largeur fixe, hauteur libre pour les rouleaux.
 * Partagés par l'impression des documents et celle des rapports.
 */
const FORMATS_IMPRESSION = [
    'a4' => ['label' => 'A4', 'largeur_mm' => 210, 'marge_mm' => 12],
    '80' => ['label' => 'Ticket 80 mm', 'largeur_mm' => 80, 'marge_mm' => 3],
    '58' => ['label' => 'Ticket 58 mm', 'largeur_mm' => 58, 'marge_mm' => 2],
];

/** Format demandé en query string, ramené à l'A4 si l'on ne le connaît pas. */
function formatImpression(?string $demande, array $autorises = ['a4', '80', '58']): string
{
    $format = (string) ($demande ?: 'a4');
    return in_array($format, $autorises, true) && isset(FORMATS_IMPRESSION[$format]) ? $format : 'a4';
}

/**
 * Condition SQL des documents qui constituent une vente réelle, sans doublon.
 *
 * Un bon de livraison est une vente à part entière tant qu'il n'a pas été
 * regroupé ; une fois converti en facture, c'est la facture qui la porte.
 * `$alias` permet de préfixer les colonnes (`d.type`…).
 */
function conditionVentesReelles(string $alias = ''): string
{
    $p = $alias ? "$alias." : '';
    return "({$p}type = 'FACTURE' OR ({$p}type = 'BON_LIVRAISON' AND {$p}statut <> 'CONVERTI'))"
        . " AND {$p}statut <> 'ANNULE'";
}

/** Retrouve le type de document depuis le segment d'URL. */
function typeDepuisChemin(string $sens, string $chemin): ?string
{
    foreach (DOCUMENTS as $type => $m) {
        if ($m['sens'] === $sens && $m['chemin'] === $chemin) return $type;
    }
    return null;
}

/** URL de la liste d'un type de document. */
function urlListe(string $type): string
{
    $m = meta($type);
    return $m['sens'] === 'VENTE' ? "/ventes/{$m['chemin']}" : "/achats/{$m['chemin']}";
}

function urlDocument(string $type, string $id): string
{
    return urlListe($type) . '/' . $id;
}

const STATUTS_DOCUMENT = [
    'BROUILLON' => ['label' => 'Brouillon', 'couleur' => 'gris'],
    'VALIDE'    => ['label' => 'Validé',    'couleur' => 'bleu'],
    'CONVERTI'  => ['label' => 'Converti',  'couleur' => 'violet'],
    'LIVRE'     => ['label' => 'Livré',     'couleur' => 'vert'],
    'CLOTURE'   => ['label' => 'Clôturé',   'couleur' => 'vert'],
    'ANNULE'    => ['label' => 'Annulé',    'couleur' => 'rouge'],
];

const STATUTS_PAIEMENT = [
    'IMPAYE'  => ['label' => 'Impayé',  'couleur' => 'rouge'],
    'PARTIEL' => ['label' => 'Partiel', 'couleur' => 'orange'],
    'PAYE'    => ['label' => 'Payé',    'couleur' => 'vert'],
];

const STATUTS_LIVRAISON = [
    'EN_PREPARATION' => ['label' => 'En préparation', 'couleur' => 'orange'],
    'EXPEDIE'        => ['label' => 'Expédié',        'couleur' => 'bleu'],
    'LIVRE'          => ['label' => 'Livré',          'couleur' => 'vert'],
    'ANNULE'         => ['label' => 'Annulé',         'couleur' => 'gris'],
];

const STATUTS_EFFET = [
    'EN_ATTENTE' => ['label' => 'En attente', 'couleur' => 'orange'],
    'ENCAISSE'   => ['label' => 'Encaissé',   'couleur' => 'vert'],
    'REJETE'     => ['label' => 'Rejeté',     'couleur' => 'rouge'],
    'ANNULE'     => ['label' => 'Annulé',     'couleur' => 'gris'],
];

/** Taux de TVA en vigueur au Maroc. */
const TAUX_TVA = ['0', '7', '10', '14', '20'];

const MODES_PAIEMENT = [
    'ESPECES'  => 'Espèces',
    'CHEQUE'   => 'Chèque',
    'EFFET'    => 'Effet',
    'VIREMENT' => 'Virement',
    'CARTE'    => 'Carte bancaire',
];

const TYPES_MOUVEMENT = [
    'ACHAT'              => 'Achat',
    'VENTE'              => 'Vente',
    'RETOUR_CLIENT'      => 'Retour client',
    'RETOUR_FOURNISSEUR' => 'Retour fournisseur',
    'INVENTAIRE'         => 'Inventaire',
    'TRANSFERT'          => 'Transfert',
    'AJUSTEMENT'         => 'Ajustement',
    'PRODUCTION'         => 'Production (atelier)',
    'CHUTE'              => 'Chute / perte',
    'CONSOMMATION'       => 'Consommable utilisé',
];

const ROLES = [
    'ADMIN'      => 'Administrateur',
    'MANAGER'    => 'Manager',
    'COMMERCIAL' => 'Commercial',
    'CAISSIER'   => 'Caissier (accueil)',
    'DESIGNER'   => 'Designer',
    'MACHINISTE' => 'Machiniste',
];

const TYPES_TIERS = [
    'CLIENT'      => 'Client',
    'FOURNISSEUR' => 'Fournisseur',
    'LES_DEUX'    => 'Client et fournisseur',
];

const TYPES_DOCUMENTATION = [
    'NOTE'            => 'Note',
    'PROCEDURE'       => 'Procédure',
    'CONTRAT'         => 'Contrat',
    'FICHE_TECHNIQUE' => 'Fiche technique',
    'AUTRE'           => 'Autre',
];
