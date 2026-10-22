<?php
/**
 * Facture au format UBL 2.1.
 *
 * La facture papier reste un rendu ; la pièce que l'administration lit est ce
 * XML. Le Maroc n'accepte que l'UBL 2.1 ou le CII, et les Émirats retiennent
 * PINT AE — un profil Peppol lui aussi bâti sur UBL. Écrire l'UBL une fois et
 * bien, c'est écrire les deux.
 *
 * Ce qui est propre au pays est rassemblé en tête du fichier, dans les
 * constantes et dans `identifiantsPartie()`. Le reste suit la norme et n'a pas
 * à bouger d'un pays à l'autre.
 *
 * ⚠ Les valeurs de `schemeID` (ICE, IF, RC) et l'éventuel bloc d'extension
 * attendu par la plateforme de la DGI ne sont pas confirmés : ils tiennent aux
 * spécifications techniques de la plateforme, à relire à leur publication. Le
 * document produit est un UBL 2.1 valide ; ce sont ces codes-là, et eux seuls,
 * qui restent à confirmer.
 */

/** Espaces de noms de la norme. */
const UBL_NS = [
    ''    => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
    'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
];

/** Type de document — UNCL 1001. 380 : facture, 381 : avoir. */
const UBL_TYPE_DOCUMENT = ['FACTURE' => '380', 'AVOIR' => '381'];

/**
 * Mode de règlement — UNCL 4461.
 * Un effet de commerce se déclare en billet à ordre (60), faute de code plus
 * proche dans la liste.
 */
const UBL_MODE_REGLEMENT = [
    'ESPECES'  => '10',
    'CHEQUE'   => '20',
    'VIREMENT' => '30',
    'CARTE'    => '48',
    'EFFET'    => '60',
];

/**
 * Unités — UN/ECE Rec. 20. L'unité est un texte libre dans l'application :
 * on reconnaît les usages courants, le reste part en « pièce ».
 */
const UBL_UNITES = [
    'piece' => 'H87', 'pièce' => 'H87', 'unite' => 'H87', 'unité' => 'H87',
    'kg' => 'KGM', 'kilo' => 'KGM', 'kilogramme' => 'KGM',
    'g' => 'GRM', 'gramme' => 'GRM',
    'l' => 'LTR', 'litre' => 'LTR',
    'm' => 'MTR', 'metre' => 'MTR', 'mètre' => 'MTR', 'ml' => 'MTR',
    'm2' => 'MTK', 'metre carre' => 'MTK', 'mètre carré' => 'MTK',
    'm3' => 'MTQ', 'metre cube' => 'MTQ', 'mètre cube' => 'MTQ',
    'sac' => 'BG', 'rouleau' => 'RO', 'boite' => 'BX', 'boîte' => 'BX',
    'carton' => 'CT', 'paquet' => 'PK', 'heure' => 'HUR', 'jour' => 'DAY',
];

/** Régime de TVA d'un taux — UNCL 5305. */
function categorieTvaUbl(float $taux): string
{
    // Une exonération et un taux zéro se ressemblent ici : l'application ne
    // distingue pas les deux. « Z » couvre le cas le plus fréquent au Maroc.
    return $taux > 0 ? 'S' : 'Z';
}

function codeUniteUbl(?string $unite): string
{
    $cle = mb_strtolower(trim((string) $unite));
    return UBL_UNITES[$cle] ?? 'H87';
}

function codeModeReglementUbl(?string $mode): ?string
{
    return UBL_MODE_REGLEMENT[(string) $mode] ?? null;
}

/** Montant : deux décimales, point décimal, sans séparateur de milliers. */
function montantUbl(float $valeur): string
{
    return number_format(arrondir($valeur), 2, '.', '');
}

function quantiteUbl(float $valeur): string
{
    return number_format($valeur, 3, '.', '');
}

/** `2026-08-26` à partir d'un `DATETIME(3)`. */
function dateUbl(?string $horodatage): string
{
    return substr((string) $horodatage, 0, 10) ?: date('Y-m-d');
}

/**
 * Ventilation par taux, prête pour l'UBL.
 *
 * La remise globale est un montant en dirhams réparti au prorata : la norme,
 * elle, veut une remise **par régime de TVA**, sinon chaque catégorie ne
 * s'équilibre plus. On répartit donc la remise entre les taux, et le dernier
 * centime va au taux qui pèse le plus — le total du XML retombe alors au
 * centime sur celui de la facture imprimée.
 */
function ventilationUbl(array $lignes, float $totalHT, float $remise, float $totalTva): array
{
    $facteur = $totalHT > 0 ? arrondir($totalHT - $remise) / $totalHT : 1.0;

    $parTaux = [];
    foreach ($lignes as $ligne) {
        $taux = (string) num($ligne['tva']);
        $parTaux[$taux] = ($parTaux[$taux] ?? 0.0) + num($ligne['total_ht']);
    }
    ksort($parTaux, SORT_NUMERIC);

    $ventilation = [];
    foreach ($parTaux as $taux => $brut) {
        $brut = arrondir($brut);
        $remiseTaux = arrondir($brut * (1 - $facteur));
        $base = arrondir($brut - $remiseTaux);

        $ventilation[] = [
            'taux'   => num($taux),
            'brut'   => $brut,
            'remise' => $remiseTaux,
            'base'   => $base,
            'tva'    => arrondir($base * num($taux) / 100),
        ];
    }

    // Le taux le plus lourd absorbe les centimes perdus dans les arrondis, sur
    // la remise comme sur la TVA : les deux totaux doivent tomber juste.
    if ($ventilation) {
        $plusLourd = 0;
        foreach ($ventilation as $i => $part) {
            if ($part['brut'] > $ventilation[$plusLourd]['brut']) $plusLourd = $i;
        }

        $ecartRemise = arrondir($remise - array_sum(array_column($ventilation, 'remise')));
        if (abs($ecartRemise) >= 0.005) {
            $ventilation[$plusLourd]['remise'] = arrondir($ventilation[$plusLourd]['remise'] + $ecartRemise);
            $ventilation[$plusLourd]['base'] = arrondir(
                $ventilation[$plusLourd]['brut'] - $ventilation[$plusLourd]['remise']);
        }

        $ecartTva = arrondir($totalTva - array_sum(array_column($ventilation, 'tva')));
        if (abs($ecartTva) >= 0.005) {
            $ventilation[$plusLourd]['tva'] = arrondir($ventilation[$plusLourd]['tva'] + $ecartTva);
        }
    }

    return $ventilation;
}

/**
 * Identifiants légaux d'une partie, dans l'ordre où le pays les attend.
 *
 * C'est **le** point où un autre pays se branche : le Maroc s'identifie par
 * l'ICE, la France par le SIRET, les Émirats par le TRN.
 */
function identifiantsPartie(array $partie, string $pays): array
{
    if ($pays === 'MA') {
        return array_values(array_filter([
            $partie['ice'] ?? null ? ['ICE', $partie['ice']] : null,
            $partie['rc'] ?? null  ? ['RC',  $partie['rc']]  : null,
        ]));
    }

    return [];
}

/**
 * Facture d'un document, en UBL 2.1.
 *
 * Ne s'applique qu'à une facture ou un avoir : un devis n'est pas une pièce
 * fiscale et n'a rien à faire sur la plateforme.
 */
function ublFacture(string $documentId): string
{
    $document = un('SELECT * FROM document WHERE id = ?', [$documentId]);
    if (!$document) throw new RuntimeException("Document introuvable : $documentId.");

    if (!isset(UBL_TYPE_DOCUMENT[$document['type']])) {
        throw new RuntimeException(
            "Seule une facture ou un avoir se met en UBL ; reçu « {$document['type']} ».");
    }

    $lignes = tous('SELECT * FROM ligne_document WHERE document_id = ? ORDER BY ordre', [$documentId]);
    $societe = un('SELECT * FROM societe LIMIT 1') ?? [];
    $lieu = un('SELECT * FROM lieu_affaire WHERE id = ?', [$document['lieu_id']]) ?? [];
    $client = $document['tiers_id']
        ? un('SELECT * FROM tiers WHERE id = ?', [$document['tiers_id']])
        : null;

    $paiements = tous('SELECT * FROM paiement WHERE document_id = ? ORDER BY date', [$documentId]);

    $devise = $document['devise'] ?? 'MAD';
    $paysSociete = $societe['pays'] ?? 'MA';

    $totalHT = num($document['total_ht']);
    $remise = num($document['remise']);
    $fraisPort = num($document['frais_port']);
    $totalTva = num($document['total_tva']);
    $totalTtc = num($document['total_ttc']);
    $paye = num($document['montant_paye']);

    $ventilation = ventilationUbl($lignes, $totalHT, $remise, $totalTva);

    // Les frais de port ne sont pas taxés ici : ils entrent en charge hors
    // taxe, et forment leur propre ventilation à taux zéro pour que la somme
    // des bases retombe sur le montant hors taxe du pied.
    $baseHorsTaxe = arrondir($totalHT - $remise + $fraisPort);

    $x = new XMLWriter();
    $x->openMemory();
    $x->setIndent(true);
    $x->setIndentString('  ');
    $x->startDocument('1.0', 'UTF-8');

    $ouvrir = function (string $nom) use ($x) { $x->startElement($nom); };
    $fermer = function () use ($x) { $x->endElement(); };

    /** Élément simple, avec ses attributs. Un contenu vide n'écrit rien. */
    $champ = function (string $nom, $valeur, array $attributs = []) use ($x) {
        if ($valeur === null || $valeur === '') return;
        $x->startElement($nom);
        foreach ($attributs as $cle => $val) $x->writeAttribute($cle, (string) $val);
        $x->text((string) $valeur);
        $x->endElement();
    };

    /** Montant : l'attribut de monnaie est obligatoire sur chaque montant. */
    $somme = function (string $nom, float $valeur) use ($x, $devise) {
        $x->startElement($nom);
        $x->writeAttribute('currencyID', $devise);
        $x->text(montantUbl($valeur));
        $x->endElement();
    };

    /** Bloc `cac:TaxCategory`, identique partout où il apparaît. */
    $categorie = function (float $taux) use ($x, $champ, $ouvrir, $fermer) {
        $ouvrir('cac:TaxCategory');
        $champ('cbc:ID', categorieTvaUbl($taux));
        $champ('cbc:Percent', number_format($taux, 2, '.', ''));
        $ouvrir('cac:TaxScheme');
        $champ('cbc:ID', 'VAT');
        $fermer();
        $fermer();
    };

    /** Partie (vendeur ou acheteur). */
    $partie = function (array $donnees, string $raisonSociale, string $pays)
        use ($x, $champ, $ouvrir, $fermer) {
        $ouvrir('cac:Party');

        foreach (identifiantsPartie($donnees, $pays) as [$schema, $valeur]) {
            $ouvrir('cac:PartyIdentification');
            $champ('cbc:ID', $valeur, ['schemeID' => $schema]);
            $fermer();
        }

        $ouvrir('cac:PartyName');
        $champ('cbc:Name', $raisonSociale);
        $fermer();

        $ouvrir('cac:PostalAddress');
        $champ('cbc:StreetName', $donnees['adresse'] ?? null);
        $champ('cbc:CityName', $donnees['ville'] ?? null);
        $champ('cbc:PostalZone', $donnees['code_postal'] ?? null);
        $ouvrir('cac:Country');
        $champ('cbc:IdentificationCode', $donnees['pays'] ?? $pays);
        $fermer();
        $fermer();

        // L'identifiant fiscal porte la TVA ; l'ICE identifie l'entreprise.
        $identifiantFiscal = $donnees['identifiant_fiscal'] ?? null;
        if ($identifiantFiscal) {
            $ouvrir('cac:PartyTaxScheme');
            $champ('cbc:CompanyID', $identifiantFiscal);
            $ouvrir('cac:TaxScheme');
            $champ('cbc:ID', 'VAT');
            $fermer();
            $fermer();
        }

        $ouvrir('cac:PartyLegalEntity');
        $champ('cbc:RegistrationName', $raisonSociale);
        $champ('cbc:CompanyID', $donnees['ice'] ?? null);
        $fermer();

        $contact = ($donnees['telephone'] ?? null) || ($donnees['email'] ?? null);
        if ($contact) {
            $ouvrir('cac:Contact');
            $champ('cbc:Telephone', $donnees['telephone'] ?? null);
            $champ('cbc:ElectronicMail', $donnees['email'] ?? null);
            $fermer();
        }

        $fermer();
    };

    $x->startElementNS(null, 'Invoice', UBL_NS['']);
    $x->writeAttributeNS('xmlns', 'cac', null, UBL_NS['cac']);
    $x->writeAttributeNS('xmlns', 'cbc', null, UBL_NS['cbc']);

    $champ('cbc:UBLVersionID', '2.1');
    $champ('cbc:ID', $document['numero']);
    $champ('cbc:IssueDate', dateUbl($document['date']));
    if ($document['date_echeance']) $champ('cbc:DueDate', dateUbl($document['date_echeance']));
    $champ('cbc:InvoiceTypeCode', UBL_TYPE_DOCUMENT[$document['type']]);
    $champ('cbc:Note', $document['notes'] ?? null);
    $champ('cbc:DocumentCurrencyCode', $devise);

    // Le document d'origine : la facture née d'un devis ou d'un bon le rappelle.
    if ($document['reference']) {
        $ouvrir('cac:OrderReference');
        $champ('cbc:ID', $document['reference']);
        $fermer();
    }

    $ouvrir('cac:AccountingSupplierParty');
    $partie(
        $societe + ['adresse' => $societe['adresse'] ?? ($lieu['adresse'] ?? null),
                    'ville'   => $societe['ville'] ?? ($lieu['ville'] ?? null)],
        (string) ($societe['nom'] ?? ''),
        $paysSociete,
    );
    $fermer();

    $ouvrir('cac:AccountingCustomerParty');
    $partie(
        $client ?? [],
        (string) ($client['nom_entreprise'] ?? $client['nom'] ?? 'Client de passage'),
        $client['pays'] ?? $paysSociete,
    );
    $fermer();

    // Le mode retenu sur le document prime ; sinon celui du premier règlement.
    $mode = codeModeReglementUbl($document['mode_reglement'] ?? null)
        ?? codeModeReglementUbl($paiements[0]['mode'] ?? null);
    if ($mode) {
        $ouvrir('cac:PaymentMeans');
        $champ('cbc:PaymentMeansCode', $mode);
        if ($document['date_echeance']) $champ('cbc:PaymentDueDate', dateUbl($document['date_echeance']));
        $champ('cbc:InstructionID', $paiements[0]['reference'] ?? null);
        $fermer();
    }

    // Remise globale, éclatée par régime de TVA.
    foreach ($ventilation as $part) {
        if ($part['remise'] <= 0) continue;
        $ouvrir('cac:AllowanceCharge');
        $champ('cbc:ChargeIndicator', 'false');
        $champ('cbc:AllowanceChargeReason', 'Remise');
        $somme('cbc:Amount', $part['remise']);
        $categorie($part['taux']);
        $fermer();
    }

    if ($fraisPort > 0) {
        $ouvrir('cac:AllowanceCharge');
        $champ('cbc:ChargeIndicator', 'true');
        $champ('cbc:AllowanceChargeReason', 'Frais de port');
        $somme('cbc:Amount', $fraisPort);
        $categorie(0);
        $fermer();
    }

    $ouvrir('cac:TaxTotal');
    $somme('cbc:TaxAmount', $totalTva);
    foreach ($ventilation as $part) {
        $ouvrir('cac:TaxSubtotal');
        $somme('cbc:TaxableAmount', $part['base']);
        $somme('cbc:TaxAmount', $part['tva']);
        $categorie($part['taux']);
        $fermer();
    }
    if ($fraisPort > 0) {
        $ouvrir('cac:TaxSubtotal');
        $somme('cbc:TaxableAmount', $fraisPort);
        $somme('cbc:TaxAmount', 0);
        $categorie(0);
        $fermer();
    }
    $fermer();

    $ouvrir('cac:LegalMonetaryTotal');
    $somme('cbc:LineExtensionAmount', $totalHT);
    $somme('cbc:TaxExclusiveAmount', $baseHorsTaxe);
    $somme('cbc:TaxInclusiveAmount', $totalTtc);
    if ($remise > 0)    $somme('cbc:AllowanceTotalAmount', $remise);
    if ($fraisPort > 0) $somme('cbc:ChargeTotalAmount', $fraisPort);
    if ($paye > 0)      $somme('cbc:PrepaidAmount', $paye);
    $somme('cbc:PayableAmount', arrondir($totalTtc - $paye));
    $fermer();

    foreach ($lignes as $index => $ligne) {
        $ouvrir('cac:InvoiceLine');
        $champ('cbc:ID', (string) ($index + 1));

        $article = $ligne['article_id']
            ? un('SELECT reference, code_barre, unite FROM article WHERE id = ?', [$ligne['article_id']])
            : null;

        $x->startElement('cbc:InvoicedQuantity');
        $x->writeAttribute('unitCode', codeUniteUbl($article['unite'] ?? null));
        $x->text(quantiteUbl(num($ligne['quantite'])));
        $x->endElement();

        $somme('cbc:LineExtensionAmount', num($ligne['total_ht']));

        // La remise de ligne est déjà déduite du total : on la déclare pour
        // que le prix unitaire affiché reste celui du catalogue.
        $brut = arrondir(num($ligne['quantite']) * num($ligne['prix_unitaire']));
        $remiseLigne = arrondir($brut - num($ligne['total_ht']));
        if ($remiseLigne > 0) {
            $ouvrir('cac:AllowanceCharge');
            $champ('cbc:ChargeIndicator', 'false');
            $champ('cbc:AllowanceChargeReason', 'Remise');
            $somme('cbc:Amount', $remiseLigne);
            $fermer();
        }

        $ouvrir('cac:Item');
        $champ('cbc:Name', $ligne['designation']);
        if ($article['reference'] ?? null) {
            $ouvrir('cac:SellersItemIdentification');
            $champ('cbc:ID', $article['reference']);
            $fermer();
        }
        if ($article['code_barre'] ?? null) {
            $ouvrir('cac:StandardItemIdentification');
            $champ('cbc:ID', $article['code_barre'], ['schemeID' => '0160']);
            $fermer();
        }
        $ouvrir('cac:ClassifiedTaxCategory');
        $champ('cbc:ID', categorieTvaUbl(num($ligne['tva'])));
        $champ('cbc:Percent', number_format(num($ligne['tva']), 2, '.', ''));
        $ouvrir('cac:TaxScheme');
        $champ('cbc:ID', 'VAT');
        $fermer();
        $fermer();
        $fermer();

        $ouvrir('cac:Price');
        $somme('cbc:PriceAmount', num($ligne['prix_unitaire']));
        $fermer();

        $fermer();
    }

    $x->endElement();
    $x->endDocument();

    return $x->outputMemory();
}
