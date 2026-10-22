<?php
/**
 * Multi-sociétés — registre, abonnements et provisionnement.
 *
 * Une installation OUTECH sert plusieurs sociétés clientes. Chacune possède **sa
 * propre base MySQL** : aucune requête de l'application (et il y en a des
 * centaines, écrites pour une seule société) ne peut atteindre les données
 * d'une autre, puisqu'elle n'est même pas connectée à sa base. La séparation
 * n'est pas une clause `WHERE` qu'on peut oublier, c'est la connexion.
 *
 * Le registre — qui est client, depuis quand, jusqu'à quand — vit dans la base
 * « console », lue par `contexteRequete()` à chaque requête HTTP.
 */

/* --------------------------------------------------------------- statuts */

/** Statut du dossier, tel qu'il est stocké. */
const STATUTS_SOCIETE = [
    'DEMANDE'   => ['label' => 'Demande reçue', 'couleur' => 'violet'],
    'ACTIVE'    => ['label' => 'Active',        'couleur' => 'vert'],
    'SUSPENDUE' => ['label' => 'Suspendue',     'couleur' => 'orange'],
    'REFUSEE'   => ['label' => 'Refusée',       'couleur' => 'rouge'],
];

/**
 * Situation réelle : le statut stocké croisé avec les dates.
 * Une société reste « ACTIVE » en base quand son essai s'achève ; c'est ici que
 * la date la fait basculer en « ESSAI_EXPIRE », sans aucune tâche planifiée.
 */
const SITUATIONS = [
    'DEMANDE'      => ['label' => 'Demande en attente', 'couleur' => 'violet'],
    'REFUSEE'      => ['label' => 'Refusée',            'couleur' => 'rouge'],
    'SUSPENDUE'    => ['label' => 'Suspendue',          'couleur' => 'orange'],
    'ESSAI'        => ['label' => 'Essai en cours',     'couleur' => 'bleu'],
    'ESSAI_EXPIRE' => ['label' => 'Essai terminé',      'couleur' => 'rouge'],
    'PRO'          => ['label' => 'Pro',                'couleur' => 'vert'],
    'PRO_EXPIRE'   => ['label' => 'Abonnement échu',    'couleur' => 'rouge'],
    'SANS_BASE'    => ['label' => 'À provisionner',     'couleur' => 'gris'],
];

/** Pages qui travaillent sur le registre plutôt que sur une société. */
const PAGES_REGISTRE = ['auth/login.php', 'demande.php', 'installation.php'];

/** Pages accessibles même quand l'abonnement est échu. */
const PAGES_LIBRES = ['abonnement.php', 'auth/deconnexion.php'];

/* --------------------------------------------------------- base « console » */

function baseConsole(): string
{
    $saas = config('saas') ?? [];
    return $saas['console'] ?? (config('bd')['base'] . '_console');
}

/** Exécute une lecture / écriture dans le registre, quelle que soit la base active. */
function dansConsole(callable $fn)
{
    return dansBase(baseConsole(), $fn);
}

/** La base console est-elle en place ? (message d'installation, sinon) */
function consolePrete(): bool
{
    static $prete = null;
    if ($prete !== null) return $prete;

    try {
        $prete = dansConsole(fn() => valeur('SELECT COUNT(*) FROM saas_societe')) !== null;
    } catch (Throwable $e) {
        $prete = false;
    }
    return $prete;
}

/* ----------------------------------------------------------------- réglages */

const REGLAGES_DEFAUT = [
    'duree_essai'         => '7',   // jours offerts à l'acceptation
    'max_societes'        => '30',  // garde-fou de la plateforme
    'inscription_ouverte' => '1',   // formulaire public de demande d'accès
    'nom_plateforme'      => 'OUTECH360',
    'contact_plateforme'  => '',
    'prix_1m'             => '199',
    'prix_6m'             => '900',
    'prix_1a'             => '1500',
    'prix_2a'             => '2300',
    'prix_3a'             => '2900',
    'prix_6a'             => '5000',
];

function reglage(string $cle, ?string $defaut = null): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (dansConsole(fn() => tous('SELECT cle, valeur FROM saas_reglage')) as $r) {
                $cache[$r['cle']] = (string) $r['valeur'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$cle] ?? $defaut ?? REGLAGES_DEFAUT[$cle] ?? '';
}

function poserReglage(string $cle, string $valeur): void
{
    dansConsole(function () use ($cle, $valeur) {
        q('INSERT INTO saas_reglage (cle, valeur, updated_at) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), updated_at = VALUES(updated_at)',
            [$cle, $valeur, maintenant()]);
    });
}

function dureeEssai(): int
{
    return max(1, (int) reglage('duree_essai'));
}

function nomPlateforme(): string
{
    return reglage('nom_plateforme') ?: 'OUTECH360';
}

/* ------------------------------------------------------------ abonnements */

/**
 * Formules proposées au client, de la mensuelle à la triennale.
 *
 * Les prix ci-dessous sont les tarifs de départ ; ceux qui s'appliquent
 * vraiment se règlent dans la console (*Réglages → Tarifs*), sans toucher au
 * code. `mois` sert à calculer la date de fin d'abonnement.
 */
const ABONNEMENTS = [
    '1m' => ['libelle' => '1 mois',  'mois' => 1,  'prix' => 199],
    '6m' => ['libelle' => '6 mois',  'mois' => 6,  'prix' => 900],
    '1a' => ['libelle' => '1 an',    'mois' => 12, 'prix' => 1500],
    '2a' => ['libelle' => '2 ans',   'mois' => 24, 'prix' => 2300],
    '3a' => ['libelle' => '3 ans',   'mois' => 36, 'prix' => 2900],
    '6a' => ['libelle' => '6 ans',   'mois' => 72, 'prix' => 5000],
];

/** Les formules avec leur prix courant et leur coût mensuel ramené. */
function abonnements(): array
{
    $formules = [];

    foreach (ABONNEMENTS as $cle => $formule) {
        $prix = (float) reglage('prix_' . $cle, (string) $formule['prix']);

        // Écrit clé par clé, et non par `$formule + [...]` : l'union de
        // tableaux garde la valeur de gauche, donc le tarif inscrit dans le
        // code l'emporterait sur celui des réglages.
        $formules[$cle] = [
            'libelle'  => $formule['libelle'],
            'mois'     => $formule['mois'],
            'prix'     => $prix,
            'par_mois' => $formule['mois'] > 0 ? $prix / $formule['mois'] : $prix,
        ];
    }

    // Économie par rapport au tarif mensuel, mise en avant sur la formule.
    $mensuel = $formules['1m']['prix'];
    foreach ($formules as $cle => $formule) {
        $plein = $mensuel * $formule['mois'];
        $formules[$cle]['economie'] = $plein > 0 ? (int) round(100 - ($formule['prix'] / $plein * 100)) : 0;
    }

    return $formules;
}

function abonnement(?string $cle): ?array
{
    if ($cle === null || $cle === '') return null;
    return abonnements()[$cle] ?? null;
}

/** Libellé lisible d'une formule choisie, prix compris. */
function libelleAbonnement(?string $cle): string
{
    $formule = abonnement($cle);
    if (!$formule) return '—';
    return $formule['libelle'] . ' · ' . montant($formule['prix']);
}

/* --------------------------------------------------------------- le registre */

function societeParId(?string $id): ?array
{
    if (!$id) return null;
    return dansConsole(fn() => un('SELECT * FROM saas_societe WHERE id = ?', [$id]));
}

function societeParCode(?string $code): ?array
{
    $code = strtolower(trim((string) $code));
    if ($code === '') return null;
    return dansConsole(fn() => un('SELECT * FROM saas_societe WHERE code = ?', [$code]));
}

/** Nombre d'espaces installés (les demandes en attente ne comptent pas). */
function nombreSocietes(): int
{
    return (int) dansConsole(fn() => valeur(
        "SELECT COUNT(*) FROM saas_societe WHERE statut IN ('ACTIVE','SUSPENDUE')"));
}

/** Reste-t-il de la place sous le plafond de la plateforme ? */
function placeDisponible(): bool
{
    $max = (int) reglage('max_societes');
    return $max <= 0 || nombreSocietes() < $max;
}

/**
 * Situation réelle d'une société : voir `SITUATIONS`.
 * Aucune écriture, aucune tâche planifiée — la date fait foi à la lecture.
 */
function situation(array $societe): string
{
    if ($societe['statut'] === 'DEMANDE')   return 'DEMANDE';
    if ($societe['statut'] === 'REFUSEE')   return 'REFUSEE';
    if ($societe['statut'] === 'SUSPENDUE') return 'SUSPENDUE';
    if (empty($societe['base']))            return 'SANS_BASE';

    $maintenant = time();

    if ($societe['formule'] === 'ESSAI') {
        $fin = $societe['essai_fin'] ? strtotime((string) $societe['essai_fin']) : 0;
        return ($fin && $fin > $maintenant) ? 'ESSAI' : 'ESSAI_EXPIRE';
    }

    $fin = $societe['abonnement_fin'] ? strtotime((string) $societe['abonnement_fin']) : 0;
    return (!$fin || $fin > $maintenant) ? 'PRO' : 'PRO_EXPIRE';
}

/** La société peut-elle ouvrir une session ? */
function accesAutorise(array $societe): bool
{
    return in_array(situation($societe), ['ESSAI', 'PRO'], true);
}

/** Jours restants avant la fin de l'essai ou de l'abonnement (null = illimité). */
function joursRestants(array $societe): ?int
{
    $fin = $societe['formule'] === 'ESSAI' ? $societe['essai_fin'] : $societe['abonnement_fin'];
    if (!$fin) return null;
    return (int) ceil((strtotime((string) $fin) - time()) / 86400);
}

function badgeSituation(array $societe): string
{
    $situation = situation($societe);
    $label = SITUATIONS[$situation]['label'];

    if ($situation === 'ESSAI') {
        $label .= ' · ' . max(0, (int) joursRestants($societe)) . ' j';
    }
    return badge($label, SITUATIONS[$situation]['couleur']);
}

/* ------------------------------------------------------- adresses publiques */

/**
 * Adresse publique d'un catalogue : `/c/epicerie-atlas`.
 *
 * Le code société ne doit jamais figurer dans une URL qu'on imprime sur une
 * vitrine ou un QR code — c'est une clé d'accès, pas une enseigne. Les
 * catalogues portent donc un nom choisi par le commerçant, réservé dans le
 * registre parce qu'il est commun à toutes les sociétés.
 */
function societeParAdressePublique(?string $adresse): ?array
{
    $adresse = strtolower(trim((string) $adresse));
    if ($adresse === '') return null;

    return dansConsole(fn() => un(
        'SELECT s.* FROM saas_catalogue c JOIN saas_societe s ON s.id = c.societe_id
          WHERE c.adresse = ?', [$adresse]));
}

/** L'adresse est-elle libre (ou déjà celle de cette société) ? */
function adresseDisponible(string $adresse, string $societeId): bool
{
    $proprietaire = dansConsole(fn() => valeur(
        'SELECT societe_id FROM saas_catalogue WHERE adresse = ?', [strtolower($adresse)]));

    return $proprietaire === null || $proprietaire === $societeId;
}

/**
 * Réserve l'adresse publique d'un catalogue, et libère celle qu'il occupait.
 * Un catalogue par lieu d'affaires : une société à plusieurs magasins tient
 * donc plusieurs adresses, une par vitrine.
 */
function reserverAdressePublique(string $adresse, string $societeId, ?string $lieuId = null): void
{
    $adresse = strtolower($adresse);

    dansConsole(function () use ($adresse, $societeId, $lieuId) {
        $sql = 'DELETE FROM saas_catalogue WHERE societe_id = ? AND adresse <> ?';
        $args = [$societeId, $adresse];

        if ($lieuId !== null) {
            $sql .= ' AND (lieu_id = ? OR lieu_id IS NULL)';
            $args[] = $lieuId;
        }
        q($sql, $args);

        q('INSERT INTO saas_catalogue (adresse, societe_id, lieu_id, updated_at) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE societe_id = VALUES(societe_id), lieu_id = VALUES(lieu_id),
                                   updated_at = VALUES(updated_at)',
            [$adresse, $societeId, $lieuId, maintenant()]);
    });
}

function libererAdressePublique(string $societeId): void
{
    dansConsole(fn() => q('DELETE FROM saas_catalogue WHERE societe_id = ?', [$societeId]));
}

/* ------------------------------------------------------------------ journal */

/** Trace une action de la console : c'est la mémoire du dossier. */
function journaliser(string $action, ?string $societeId = null, ?string $detail = null): void
{
    $admin = $_SESSION['console'] ?? null;

    dansConsole(function () use ($action, $societeId, $detail, $admin) {
        inserer('saas_journal', [
            'societe_id' => $societeId,
            'admin_id'   => $admin['admin_id'] ?? null,
            'acteur'     => $admin['nom'] ?? null,
            'action'     => $action,
            'detail'     => $detail,
            'ip'         => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    });
}

/* ---------------------------------------------------------- provisionnement */

/** Nombre de chiffres accolés au nom dans le code société. */
const CHIFFRES_CODE = 24;

/**
 * Code société : le nom de la société suivi de 24 chiffres tirés au sort,
 * par exemple `oulmadou-service-703481562907314885206194`.
 *
 * Le nom garde le code reconnaissable d'un coup d'œil ; les chiffres le
 * rendent impossible à deviner et permettent à deux sociétés de porter la
 * même enseigne sans se disputer un code. Il est généré, jamais saisi — la
 * console l'affiche avec un bouton « copier ».
 */
function codeSociete(string $texte): string
{
    $nom = substr(slugifier($texte), 0, 40) ?: 'societe';

    $chiffres = '';
    for ($i = 0; $i < CHIFFRES_CODE; $i++) $chiffres .= random_int(0, 9);

    return $nom . '-' . $chiffres;
}

/** Le nom lisible d'un code, sans sa suite de chiffres. */
function nomDuCode(string $code): string
{
    return preg_replace('/-\d{6,}$/', '', $code) ?? $code;
}

/**
 * Code abrégé pour une liste : `oulmadou-service-…1477`.
 * Un code sans suite de chiffres (société d'avant ce format) est laissé tel
 * quel — l'abréger reviendrait à couper son nom en deux.
 */
function codeCourt(string $code): string
{
    if (!preg_match('/-(\d{6,})$/', $code, $m)) return $code;
    return nomDuCode($code) . '-…' . substr($m[1], -4);
}

/**
 * Nom de base dérivé du code : `outech_oulmadou_service_703481562907`.
 *
 * MySQL borne un nom de base à 64 caractères — le code, lui, en fait une
 * quarantaine. On garde donc le nom lisible et une partie des chiffres :
 * douze suffisent largement à ce que deux sociétés homonymes ne se croisent
 * jamais, et `provisionner()` refuse de toute façon une base déjà occupée.
 */
function nomBasePour(string $code): string
{
    $prefixe = config('saas')['prefixe'] ?? 'outech_';

    $nom = substr(nomDuCode($code), 0, 24);
    preg_match('/(\d+)$/', $code, $m);
    $chiffres = substr($m[1] ?? '', 0, 12);

    $base = $prefixe . str_replace('-', '_', $nom) . ($chiffres === '' ? '' : '_' . $chiffres);

    return substr($base, 0, 60);
}

/**
 * Un nom de base ne peut pas voyager en paramètre préparé : il est concaténé
 * dans le SQL. Il est donc validé au caractère près, et tout ce qui sort de cet
 * alphabet est refusé.
 */
function baseValide(string $base): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{1,60}$/', $base);
}

/** Découpe un fichier .sql en instructions exécutables. */
function instructionsSql(string $sql): array
{
    $lignes = [];
    foreach (preg_split('/\R/', $sql) as $ligne) {
        $nette = trim($ligne);
        if ($nette === '' || str_starts_with($nette, '--')) continue;
        $lignes[] = $ligne;
    }

    $instructions = [];
    foreach (explode(";\n", implode("\n", $lignes) . "\n") as $instruction) {
        $instruction = trim($instruction);
        if ($instruction !== '') $instructions[] = $instruction;
    }
    return $instructions;
}

/** La base existe-t-elle déjà, et contient-elle des tables ? */
function baseOccupee(string $base): bool
{
    try {
        if (estSqlite()) {
            if (!is_file(fichierSqlite($base))) return false;
            return (int) ouvrirPdo($base)->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn() > 0;
        }
        return (int) dansConsole(fn() => valeur(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = ?', [$base])) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Crée la base d'une société.
 * @throws RuntimeException si le nom est invalide ou l'hébergeur refuse.
 */
function creerBase(string $base): void
{
    if (!baseValide($base)) throw new RuntimeException("Nom de base invalide : « $base ».");

    // SQLite : le fichier se crée à la première connexion.
    if (estSqlite()) {
        ouvrirPdo($base);
        return;
    }

    try {
        ouvrirPdo('')->exec(
            "CREATE DATABASE IF NOT EXISTS `$base` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        throw new RuntimeException(
            "La base « $base » n'a pas pu être créée : " . $e->getMessage()
            . " — créez-la depuis le panneau de l'hébergeur, puis relancez l'installation.");
    }
}

/**
 * Colonnes apparues après la première version du schéma.
 *
 * `CREATE TABLE IF NOT EXISTS` laisse intacte une table déjà présente : les
 * bases installées avant ne verraient jamais ces colonnes. Elles sont donc
 * posées une par une, sans effet là où elles existent déjà.
 */
const COLONNES_AJOUTEES = [
    ['catalogue', 'whatsapp',         'VARCHAR(32) NULL AFTER `afficher_prix`'],
    ['catalogue', 'whatsapp_actif',   'TINYINT(1) NOT NULL DEFAULT 0 AFTER `whatsapp`'],
    ['catalogue', 'whatsapp_message', 'VARCHAR(255) NULL AFTER `whatsapp_actif`'],
    // Imprimerie : laize des rouleaux et rattachement des sorties d'atelier.
    ['article',         'laize',           'DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER `unite`'],
    ['mouvement_stock', 'consommation_id', 'VARCHAR(30) NULL AFTER `document_id`'],
    ['travail',         'motif_annulation', 'VARCHAR(255) NULL AFTER `notes`'],
    ['travail',         'matiere_sortie_le', 'DATETIME(3) NULL AFTER `motif_annulation`'],
    ['article',         'type_impression',
        "ENUM('AUCUN','SUPPORT','PRESTATION') NOT NULL DEFAULT 'AUCUN' AFTER `laize`"],
    ['article',         'support_id',        'VARCHAR(30) NULL AFTER `type_impression`'],
    ['article',         'conso_unitaire',    'DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER `support_id`'],
    ['travail_ligne',   'support_id',        'VARCHAR(30) NULL AFTER `unites`'],
    ['travail_ligne',   'conso_prevue',      'DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER `support_id`'],
    // Conformité et ouverture à d'autres pays. Le pays porte les règles
    // fiscales ; la monnaie et le mode de règlement sont exigés par les
    // formats de facture électronique (UBL au Maroc, PINT ailleurs). Posées
    // dès maintenant avec leurs valeurs marocaines : elles ne coûtent rien
    // aujourd'hui et évitent de reprendre le modèle de données plus tard.
    ['societe',  'pays',               "CHAR(2) NOT NULL DEFAULT 'MA' AFTER `devise`"],
    ['tiers',    'identifiant_fiscal', 'VARCHAR(191) NULL AFTER `rc`'],
    ['tiers',    'code_postal',        'VARCHAR(16) NULL AFTER `ville`'],
    ['tiers',    'pays',               "CHAR(2) NOT NULL DEFAULT 'MA' AFTER `code_postal`"],
    ['document', 'devise',             "CHAR(3) NOT NULL DEFAULT 'MAD' AFTER `statut_paiement`"],
    ['document', 'mode_reglement',     'VARCHAR(16) NULL AFTER `devise`'],
    // Habillage du catalogue public.
    ['catalogue', 'theme',   "VARCHAR(16) NOT NULL DEFAULT 'vitrine' AFTER `whatsapp_message`"],
    ['catalogue', 'couleur', "VARCHAR(7) NOT NULL DEFAULT '#1b2153' AFTER `theme`"],
    ['catalogue', 'domaine', 'VARCHAR(190) NULL AFTER `couleur`'],
    // Consommables : une dépense peut naître d'une sortie de stock (papier,
    // toner…), et le mouvement de stock sait de quelle dépense il vient.
    ['depense',         'origine',    "ENUM('SAISIE','CONSOMMATION') NOT NULL DEFAULT 'SAISIE' AFTER `recurrente`"],
    ['mouvement_stock', 'depense_id', 'VARCHAR(30) NULL AFTER `consommation_id`'],
];

/**
 * Index apparus après la première version du schéma, posés comme les colonnes.
 */
const INDEX_AJOUTES = [
    ['mouvement_stock', 'mouvement_consommation', '(`consommation_id`)'],
    ['article',         'article_support',        '(`support_id`)'],
    ['travail_ligne',   'travail_ligne_support',  '(`support_id`)'],
    ['mouvement_stock', 'mouvement_depense',      '(`depense_id`)'],
];

/**
 * Colonnes ENUM dont la liste de valeurs s'est enrichie.
 *
 * Une base installée avant garde l'ancienne liste : MySQL refuserait alors
 * silencieusement (ou tronquerait) une valeur nouvelle. La définition est
 * réécrite telle quelle, ce qui est sans effet si elle est déjà à jour.
 */
const ENUMS_ETENDUS = [
    ['mouvement_stock', 'type',
        "ENUM('ACHAT','VENTE','RETOUR_CLIENT','RETOUR_FOURNISSEUR','INVENTAIRE','TRANSFERT','AJUSTEMENT','PRODUCTION','CHUTE','CONSOMMATION') NOT NULL"],
    ['utilisateur', 'role',
        "ENUM('ADMIN','MANAGER','COMMERCIAL','CAISSIER','DESIGNER','MACHINISTE') NOT NULL DEFAULT 'COMMERCIAL'"],
];

/**
 * Applique `install/outech.sql` à une base : toutes les tables du logiciel.
 * Le fichier n'utilise que des `CREATE TABLE IF NOT EXISTS` — le rejouer sur
 * une base déjà installée ne détruit rien.
 */
function appliquerSchema(string $base): void
{
    if (!baseValide($base)) throw new RuntimeException("Nom de base invalide : « $base ».");

    $sql = @file_get_contents(RACINE . '/install/outech.sql');
    if ($sql === false) throw new RuntimeException('Schéma introuvable : install/outech.sql');

    try {
        $pdo = ouvrirPdo($base);
    } catch (PDOException $e) {
        // Hébergeur qui interdit `CREATE DATABASE` : la base doit exister
        // avant qu'on y pose les tables. Autant dire quoi faire, plutôt que
        // de renvoyer le message brut de MySQL.
        if (!(config('saas')['creation_bases'] ?? true)) {
            throw new RuntimeException(
                "La base « $base » n'existe pas encore. Créez-la dans le panneau de votre "
                . "hébergeur (Bases de données → Nouvelle base MySQL), puis reprenez : "
                . "son nom se saisit dans le dossier de la société, champ « Base de données ».");
        }
        throw new RuntimeException("Base « $base » injoignable : " . $e->getMessage());
    }

    foreach (instructionsSql($sql) as $instruction) {
        $pdo->exec($instruction);
    }

    foreach (COLONNES_AJOUTEES as [$table, $colonne, $definition]) {
        ajouterColonne($pdo, $table, $colonne, $definition);
    }

    foreach (INDEX_AJOUTES as [$table, $index, $colonnes]) {
        ajouterIndex($pdo, $table, $index, $colonnes);
    }

    foreach (ENUMS_ETENDUS as [$table, $colonne, $definition]) {
        $pdo->exec("ALTER TABLE `$table` MODIFY `$colonne` $definition");
    }
}

/**
 * Installe la base « console » : la crée si l'hébergeur le permet, puis y
 * applique `install/console.sql`. Rejouable sans risque.
 */
function installerConsole(): void
{
    $base = baseConsole();
    if (!baseValide($base)) throw new RuntimeException("Nom de base console invalide : « $base ».");

    if (config('saas')['creation_bases'] ?? true) creerBase($base);

    $sql = @file_get_contents(RACINE . '/install/console.sql');
    if ($sql === false) throw new RuntimeException('Schéma introuvable : install/console.sql');

    try {
        $pdo = ouvrirPdo($base);
    } catch (PDOException $e) {
        throw new RuntimeException(
            "Base « $base » injoignable : " . $e->getMessage()
            . " — créez-la depuis phpMyAdmin, puis rechargez cette page.");
    }

    foreach (instructionsSql($sql) as $instruction) $pdo->exec($instruction);

    // `CREATE TABLE IF NOT EXISTS` ne touche pas une table déjà là : les
    // colonnes ajoutées après coup se posent ici, une par une.
    ajouterColonne($pdo, 'saas_catalogue', 'lieu_id', 'VARCHAR(30) NULL AFTER `societe_id`');
    ajouterColonne($pdo, 'saas_societe', 'abonnement_choisi',
        'VARCHAR(8) NULL AFTER `abonnement_fin`');
    ajouterColonne($pdo, 'saas_societe', 'abonnement_montant',
        'DECIMAL(10,2) NULL AFTER `abonnement_choisi`');
}

/** Ajoute une colonne si elle manque. Sans effet si elle est déjà présente. */
function ajouterColonne(PDO $pdo, string $table, string $colonne, string $definition): void
{
    if (colonneExiste($pdo, $table, $colonne)) return;

    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colonne` $definition");
}

/** Ajoute un index s'il manque. Sans effet s'il est déjà présent. */
function ajouterIndex(PDO $pdo, string $table, string $index, string $colonnes): void
{
    if (indexExiste($pdo, $table, $index)) return;

    $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` $colonnes");
}

/** Mot de passe provisoire lisible, communiqué au client puis changé par lui. */
function motDePasseProvisoire(): string
{
    $syllabes = ['ka', 'ri', 'mo', 'sa', 'lu', 'ne', 'ta', 'vi', 'zo', 'ba', 'de', 'fi'];
    $mot = '';
    for ($i = 0; $i < 4; $i++) $mot .= $syllabes[random_int(0, count($syllabes) - 1)];
    return ucfirst($mot) . random_int(100, 999);
}

/**
 * Installe l'espace d'une société : base, schéma, société, lieu d'affaires et
 * compte administrateur. Rejouable — un espace déjà installé n'est pas touché.
 *
 * @return array{base: string, username: string, motdepasse: ?string}
 */
function provisionner(array $societe, array $compte = []): array
{
    $choisie = trim((string) ($societe['base'] ?? ''));
    $base = $choisie ?: nomBasePour($societe['code']);

    if (!baseValide($base)) throw new RuntimeException("Nom de base invalide : « $base ».");

    // Le nom déduit du code peut tomber sur une base qui existe déjà et sert à
    // autre chose : on refuse d'y installer une société par accident. Le
    // gestionnaire tranche, en changeant le code ou en nommant la base.
    if ($choisie === '' && baseOccupee($base)) {
        throw new RuntimeException(
            "La base « $base » existe déjà et n'est pas vide. Changez le code de la société, "
            . "ou indiquez explicitement la base à utiliser dans le dossier.");
    }

    if (config('saas')['creation_bases'] ?? true) creerBase($base);
    appliquerSchema($base);

    $username   = trim((string) ($compte['username'] ?? '')) ?: 'admin';
    $motDePasse = (string) ($compte['motdepasse'] ?? '') ?: motDePasseProvisoire();
    $cree       = false;

    dansBase($base, function () use ($societe, $compte, $username, $motDePasse, &$cree) {
        transaction(function () use ($societe, $compte, $username, $motDePasse, &$cree) {
            if (!un('SELECT id FROM societe LIMIT 1')) {
                inserer('societe', [
                    'nom'        => $societe['nom'],
                    'ville'      => ouNull($societe['ville'] ?? null),
                    'telephone'  => ouNull($societe['contact_telephone'] ?? null),
                    'email'      => ouNull($societe['contact_email'] ?? null),
                    'devise'     => 'dhs',
                    'tva_defaut' => 20,
                ]);
            }

            $lieuId = valeur('SELECT id FROM lieu_affaire ORDER BY code LIMIT 1');
            if (!$lieuId) {
                $lieuId = inserer('lieu_affaire', [
                    'code'  => 'PRINCIPAL',
                    'nom'   => 'Magasin principal',
                    'ville' => ouNull($societe['ville'] ?? null),
                    'actif' => 1,
                ]);
            }

            // Un espace déjà pourvu d'un compte n'en reçoit pas un second : le
            // provisionnement peut être relancé sans rien écraser.
            if ((int) valeur('SELECT COUNT(*) FROM utilisateur') > 0) return;

            $userId = inserer('utilisateur', [
                'nom'          => trim((string) ($compte['nom'] ?? ''))
                    ?: (($societe['contact_nom'] ?? '') ?: 'Administrateur'),
                'username'     => $username,
                'email'        => ouNull($societe['contact_email'] ?? null),
                'mot_de_passe' => password_hash($motDePasse, PASSWORD_BCRYPT),
                'role'         => 'ADMIN',
                'actif'        => 1,
            ]);
            inserer('utilisateur_lieu', ['user_id' => $userId, 'lieu_id' => $lieuId]);
            $cree = true;
        });
    });

    return ['base' => $base, 'username' => $username, 'motdepasse' => $cree ? $motDePasse : null];
}

/**
 * Accepte une demande (ou active une société créée depuis la console) :
 * l'espace est installé et l'essai démarre pour `duree_essai` jours.
 */
function accepterSociete(array $societe, array $compte = []): array
{
    $resultat = provisionner($societe, $compte);
    $fin = (new DateTime())->modify('+' . dureeEssai() . ' days');

    dansConsole(fn() => modifier('saas_societe', $societe['id'], [
        'base'           => $resultat['base'],
        'statut'         => 'ACTIVE',
        'formule'        => 'ESSAI',
        'essai_fin'      => $fin->format('Y-m-d H:i:s.v'),
        'abonnement_fin' => null,
        'admin_username' => $resultat['username'],
        'accepte_le'     => maintenant(),
        'motif_refus'    => null,
    ]));

    journaliser('acceptation', $societe['id'],
        'Espace installé (' . $resultat['base'] . '), essai de ' . dureeEssai() . ' jours.');

    return $resultat;
}

/** Réinitialise le mot de passe du compte administrateur d'une société. */
function reinitialiserAdmin(array $societe, ?string $username = null): array
{
    if (empty($societe['base'])) throw new RuntimeException("Cette société n'a pas encore d'espace.");

    $username = trim((string) ($username ?: $societe['admin_username'] ?: 'admin'));
    $motDePasse = motDePasseProvisoire();

    dansBase($societe['base'], function () use ($username, $motDePasse, $societe) {
        $utilisateur = un('SELECT * FROM utilisateur WHERE username = ? LIMIT 1', [$username])
            ?: un("SELECT * FROM utilisateur WHERE role = 'ADMIN' ORDER BY created_at LIMIT 1");

        if ($utilisateur) {
            modifier('utilisateur', $utilisateur['id'], [
                'mot_de_passe' => password_hash($motDePasse, PASSWORD_BCRYPT),
                'actif'        => 1,
            ]);
            return;
        }

        // Espace dont le compte administrateur a été supprimé : on le rétablit.
        $lieuId = valeur('SELECT id FROM lieu_affaire ORDER BY code LIMIT 1');
        $id = inserer('utilisateur', [
            'nom'          => ($societe['contact_nom'] ?? '') ?: 'Administrateur',
            'username'     => $username,
            'mot_de_passe' => password_hash($motDePasse, PASSWORD_BCRYPT),
            'role'         => 'ADMIN',
            'actif'        => 1,
        ]);
        if ($lieuId) inserer('utilisateur_lieu', ['user_id' => $id, 'lieu_id' => $lieuId]);
    });

    journaliser('mot-de-passe', $societe['id'], 'Mot de passe administrateur réinitialisé.');

    return ['username' => $username, 'motdepasse' => $motDePasse];
}

/* ------------------------------------------------------- lecture d'un espace */

/** Chiffres clés d'une société, lus dans sa base. */
function statsSociete(array $societe): array
{
    $vide = ['utilisateurs' => 0, 'articles' => 0, 'tiers' => 0, 'documents' => 0,
             'ca' => 0.0, 'derniere_activite' => null, 'taille' => 0];

    if (empty($societe['base'])) return $vide;

    try {
        return dansBase($societe['base'], fn() => [
            'utilisateurs' => (int) valeur('SELECT COUNT(*) FROM utilisateur'),
            'articles'     => (int) valeur('SELECT COUNT(*) FROM article'),
            'tiers'        => (int) valeur('SELECT COUNT(*) FROM tiers'),
            'documents'    => (int) valeur('SELECT COUNT(*) FROM document'),
            // Même règle que les rapports : factures + bons non regroupés.
            'ca' => (float) valeur(
                "SELECT COALESCE(SUM(total_ttc), 0) FROM document
                  WHERE type IN ('FACTURE','BON_LIVRAISON') AND statut <> 'ANNULE'
                    AND NOT (type = 'BON_LIVRAISON' AND statut = 'CONVERTI')"),
            'derniere_activite' => valeur('SELECT MAX(updated_at) FROM document'),
            'taille'            => tailleBase($societe['base']),
        ]);
    } catch (Throwable $e) {
        return $vide;
    }
}

/** Taille de la base en octets (information_schema). */
function tailleBase(string $base): int
{
    try {
        if (estSqlite()) {
            $fichier = fichierSqlite($base);
            return is_file($fichier) ? (int) filesize($fichier) : 0;
        }
        return (int) dansConsole(fn() => valeur(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES
              WHERE table_schema = ?', [$base]));
    } catch (Throwable $e) {
        return 0;
    }
}

function poids(int $octets): string
{
    if ($octets <= 0) return '—';
    $unites = ['o', 'Ko', 'Mo', 'Go'];
    $rang = (int) min(3, floor(log(max($octets, 1), 1024)));
    return nombre(round($octets / (1024 ** $rang), $rang ? 1 : 0)) . ' ' . $unites[$rang];
}

/* ---------------------------------------------------- contexte de la requête */

/** Société de la session en cours (relue dans le registre à chaque requête). */
function societeSession(): ?array
{
    static $societe = null;
    static $charge = false;
    if ($charge) return $societe;
    $charge = true;

    return $societe = societeParId(sessionCourante()['societe_id'] ?? null);
}

/**
 * Adresse publique servie par la requête en cours (catalogue, images).
 * C'est elle qui préfixe les URL d'images d'une page sans session, puisqu'il
 * n'y a rien d'autre pour désigner la société.
 */
function adressePublique(?string $adresse = null): ?string
{
    if ($adresse !== null) $GLOBALS['outech_adresse_publique'] = $adresse;
    return $GLOBALS['outech_adresse_publique'] ?? null;
}

/**
 * Pose la base de travail de la requête et vérifie le droit d'entrer.
 *
 * - pages `console/…`, connexion, demande : registre de la plateforme ;
 * - pages publiques `{code}` : base de la société visée, sans session ;
 * - pages applicatives : base de la société de la session, abonnement vérifié.
 */
function contexteRequete(string $fichier, array $params): void
{
    if (str_starts_with($fichier, 'console/') || in_array($fichier, PAGES_REGISTRE, true)) {
        utiliserBase(baseConsole());
        return;
    }

    // Pages publiques : la société est désignée par l'adresse du catalogue,
    // jamais par son code — celui-ci ouvre une session, il ne s'affiche pas
    // sur une vitrine.
    if (isset($params['adresse'])) {
        $societe = societeParAdressePublique($params['adresse']);
        if (!$societe || empty($societe['base']) || !accesAutorise($societe)) {
            // 404 nu : rendre la coquille applicative ici lirait la base de la
            // société du visiteur, qui n'a rien à voir avec celle demandée.
            http_response_code(404);
            exit('Page introuvable.');
        }
        adressePublique(strtolower((string) $params['adresse']));
        utiliserBase($societe['base']);
        return;
    }

    $session = sessionCourante();
    if (!$session) rediriger('/login');

    $societe = societeSession();
    if (!$societe || empty($societe['base'])) {
        fermerSession();
        rediriger('/login', "Cet espace n'est plus disponible.", 'erreur');
    }

    utiliserBase($societe['base']);

    // Une session ouverte depuis la console consulte l'espace même suspendu ou
    // échu : c'est justement là qu'il faut pouvoir y entrer.
    if (!empty($session['console'])) return;

    if (!accesAutorise($societe) && !in_array($fichier, PAGES_LIBRES, true)) {
        rediriger('/abonnement');
    }
}
