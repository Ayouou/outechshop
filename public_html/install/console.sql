-- ---------------------------------------------------------------------------
-- OUTECH — base « console » (multi-sociétés)
--
-- Cette base ne contient AUCUNE donnée commerciale : elle ne sert qu'à tenir
-- le registre des sociétés clientes, leurs abonnements et les comptes du
-- gestionnaire de la plateforme.
--
-- Les données de chaque société vivent dans leur propre base MySQL
-- (`outech_<code>`), créée à l'acceptation. Aucune requête d'un client ne peut
-- donc atteindre les données d'un autre : la séparation est physique.
--
-- Import depuis phpMyAdmin :
--   1. Créer une base (ex. « outech_console ») en interclassement utf8mb4_unicode_ci
--   2. Onglet « Importer » → choisir ce fichier → Exécuter
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ------------------------------------------------- comptes de la plateforme

CREATE TABLE IF NOT EXISTS `saas_admin` (
  `id`            VARCHAR(30)  NOT NULL,
  `nom`           VARCHAR(191) NOT NULL,
  `username`      VARCHAR(191) NOT NULL,
  `email`         VARCHAR(191) NULL,
  `mot_de_passe`  VARCHAR(255) NOT NULL,
  `actif`         TINYINT(1)   NOT NULL DEFAULT 1,
  `dernier_login` DATETIME(3)  NULL,
  `created_at`    DATETIME(3)  NOT NULL,
  `updated_at`    DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `saas_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- sociétés clientes

-- `statut`  : où en est le dossier (demande reçue, active, suspendue, refusée)
-- `formule` : ESSAI (borné par `essai_fin`) ou PRO (borné par `abonnement_fin`,
--             laissé à NULL pour un abonnement sans date de fin).
CREATE TABLE IF NOT EXISTS `saas_societe` (
  `id`                 VARCHAR(30)  NOT NULL,
  `code`               VARCHAR(64)  NOT NULL,
  `nom`                VARCHAR(191) NOT NULL,
  `base`               VARCHAR(64)  NULL,
  `statut`             ENUM('DEMANDE','ACTIVE','SUSPENDUE','REFUSEE') NOT NULL DEFAULT 'DEMANDE',
  `formule`            ENUM('ESSAI','PRO') NOT NULL DEFAULT 'ESSAI',
  `essai_fin`          DATETIME(3)  NULL,
  `abonnement_fin`     DATETIME(3)  NULL,
  `contact_nom`        VARCHAR(191) NULL,
  `contact_email`      VARCHAR(191) NULL,
  `contact_telephone`  VARCHAR(191) NULL,
  `ville`              VARCHAR(191) NULL,
  `activite`           VARCHAR(191) NULL,
  `message`            TEXT         NULL,
  `notes`              TEXT         NULL,
  `motif_refus`        TEXT         NULL,
  `admin_username`     VARCHAR(191) NULL,
  `accepte_le`         DATETIME(3)  NULL,
  `refuse_le`          DATETIME(3)  NULL,
  `derniere_connexion` DATETIME(3)  NULL,
  `created_at`         DATETIME(3)  NOT NULL,
  `updated_at`         DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `saas_societe_code` (`code`),
  KEY `saas_societe_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- journal

-- Trace horodatée de tout ce que fait la console : acceptation, refus, passage
-- en Pro, suspension, ouverture d'un espace client, réinitialisation d'un mot
-- de passe. C'est la mémoire du dossier de chaque société.
CREATE TABLE IF NOT EXISTS `saas_journal` (
  `id`         VARCHAR(30)  NOT NULL,
  `societe_id` VARCHAR(30)  NULL,
  `admin_id`   VARCHAR(30)  NULL,
  `acteur`     VARCHAR(191) NULL,
  `action`     VARCHAR(64)  NOT NULL,
  `detail`     TEXT         NULL,
  `ip`         VARCHAR(64)  NULL,
  `created_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `saas_journal_societe` (`societe_id`),
  KEY `saas_journal_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- adresses publiques

-- Les catalogues publics s'adressent par un nom choisi par le commerçant
-- (`/c/epicerie-atlas`) — le code société, lui, ne sort jamais en public.
-- Ces adresses vivant dans un espace commun à toutes les sociétés, c'est ici
-- qu'elles sont réservées : la clé primaire suffit à interdire les doublons.
-- `lieu_id` : une société à plusieurs magasins publie un catalogue par lieu,
-- chacun avec sa propre adresse.
CREATE TABLE IF NOT EXISTS `saas_catalogue` (
  `adresse`    VARCHAR(120) NOT NULL,
  `societe_id` VARCHAR(30)  NOT NULL,
  `lieu_id`    VARCHAR(30)  NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`adresse`),
  KEY `saas_catalogue_societe` (`societe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- réglages

-- Domaines propres. Comme les adresses, ils sont communs a toute la
-- plateforme : deux commercants ne peuvent pas revendiquer le meme.
-- Pointer le DNS et obtenir le certificat restent des gestes d'hebergement ;
-- cette table dit seulement quel catalogue servir quand l'hote repond.

CREATE TABLE IF NOT EXISTS `saas_domaine` (
  `domaine`    VARCHAR(190) NOT NULL,
  `societe_id` VARCHAR(30)  NOT NULL,
  `adresse`    VARCHAR(120) NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`domaine`),
  KEY `saas_domaine_societe` (`societe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `saas_reglage` (
  `cle`        VARCHAR(64) NOT NULL,
  `valeur`     TEXT        NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
