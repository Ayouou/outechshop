-- ---------------------------------------------------------------------------
-- OUTECH — Gestion commerciale · schéma MySQL / MariaDB
--
-- Import depuis phpMyAdmin :
--   1. Créer une base (ex. « outech ») en interclassement utf8mb4_unicode_ci
--   2. Onglet « Importer » → choisir ce fichier → Exécuter
--
-- L'interclassement utf8mb4_unicode_ci est insensible à la casse : la
-- recherche « fact » trouve « Facture » sans traitement particulier (c'est ce
-- que faisait `mode: "insensitive"` côté PostgreSQL).
--
-- Les identifiants sont des chaînes (cuid) de 25 caractères, comme dans
-- l'application d'origine : une sauvegarde JSON de l'ancienne base se réimporte
-- telle quelle, identifiants compris.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ------------------------------------------------------------ société / lieux

CREATE TABLE IF NOT EXISTS `societe` (
  `id`                 VARCHAR(30)   NOT NULL,
  `nom`                VARCHAR(191)  NOT NULL DEFAULT 'OUTECH',
  `ice`                VARCHAR(191)  NULL,
  `rc`                 VARCHAR(191)  NULL,
  `identifiant_fiscal` VARCHAR(191)  NULL,
  `cnss`               VARCHAR(191)  NULL,
  `patente`            VARCHAR(191)  NULL,
  `adresse`            TEXT          NULL,
  `ville`              VARCHAR(191)  NULL,
  `telephone`          VARCHAR(191)  NULL,
  `email`              VARCHAR(191)  NULL,
  `site_web`           VARCHAR(191)  NULL,
  `logo_url`           TEXT          NULL,
  `devise`             VARCHAR(16)   NOT NULL DEFAULT 'dhs',
  `pays`               CHAR(2)       NOT NULL DEFAULT 'MA',
  `tva_defaut`         DECIMAL(5,2)  NOT NULL DEFAULT 20,
  `pied_facture`       TEXT          NULL,
  `created_at`         DATETIME(3)   NOT NULL,
  `updated_at`         DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lieu_affaire` (
  `id`         VARCHAR(30)  NOT NULL,
  `code`       VARCHAR(64)  NOT NULL,
  `nom`        VARCHAR(191) NOT NULL,
  `adresse`    TEXT         NULL,
  `ville`      VARCHAR(191) NULL,
  `telephone`  VARCHAR(191) NULL,
  `actif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME(3)  NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lieu_affaire_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- utilisateurs

CREATE TABLE IF NOT EXISTS `utilisateur` (
  `id`            VARCHAR(30)  NOT NULL,
  `nom`           VARCHAR(191) NOT NULL,
  `username`      VARCHAR(191) NOT NULL,
  `email`         VARCHAR(191) NULL,
  `mot_de_passe`  VARCHAR(255) NOT NULL,
  -- DESIGNER et MACHINISTE ne voient que l'atelier (voir `navigation.php`).
  `role`          ENUM('ADMIN','MANAGER','COMMERCIAL','CAISSIER','DESIGNER','MACHINISTE') NOT NULL DEFAULT 'COMMERCIAL',
  `telephone`     VARCHAR(191) NULL,
  `actif`         TINYINT(1)   NOT NULL DEFAULT 1,
  `dernier_login` DATETIME(3)  NULL,
  `created_at`    DATETIME(3)  NOT NULL,
  `updated_at`    DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `utilisateur_username` (`username`),
  UNIQUE KEY `utilisateur_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utilisateur_lieu` (
  `user_id` VARCHAR(30) NOT NULL,
  `lieu_id` VARCHAR(30) NOT NULL,
  PRIMARY KEY (`user_id`, `lieu_id`),
  KEY `utilisateur_lieu_lieu` (`lieu_id`),
  CONSTRAINT `fk_ul_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ul_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- tiers

CREATE TABLE IF NOT EXISTS `groupe_client` (
  `id`              VARCHAR(30)  NOT NULL,
  `nom`             VARCHAR(191) NOT NULL,
  `remise_pourcent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `description`     TEXT         NULL,
  `created_at`      DATETIME(3)  NOT NULL,
  `updated_at`      DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupe_client_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groupe_prix` (
  `id`          VARCHAR(30)  NOT NULL,
  `nom`         VARCHAR(191) NOT NULL,
  `description` TEXT         NULL,
  `actif`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME(3)  NOT NULL,
  `updated_at`  DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupe_prix_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tiers` (
  `id`                  VARCHAR(30)  NOT NULL,
  `code`                VARCHAR(64)  NOT NULL,
  `type`                ENUM('CLIENT','FOURNISSEUR','LES_DEUX') NOT NULL DEFAULT 'CLIENT',
  `nom`                 VARCHAR(191) NOT NULL,
  `nom_entreprise`      VARCHAR(191) NULL,
  `email`               VARCHAR(191) NULL,
  `telephone`           VARCHAR(191) NULL,
  `mobile`              VARCHAR(191) NULL,
  `ice`                 VARCHAR(191) NULL,
  `rc`                  VARCHAR(191) NULL,
  `identifiant_fiscal`  VARCHAR(191) NULL,
  `adresse`             TEXT         NULL,
  `ville`               VARCHAR(191) NULL,
  `code_postal`         VARCHAR(16)  NULL,
  `pays`                CHAR(2)      NOT NULL DEFAULT 'MA',
  `conditions_paiement` TEXT         NULL,
  `solde_ouverture`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `plafond_credit`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `actif`               TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`               TEXT         NULL,
  `groupe_id`           VARCHAR(30)  NULL,
  `groupe_prix_id`      VARCHAR(30)  NULL,
  `created_at`          DATETIME(3)  NOT NULL,
  `updated_at`          DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tiers_code` (`code`),
  KEY `tiers_type` (`type`),
  KEY `tiers_nom` (`nom`),
  KEY `tiers_groupe` (`groupe_id`),
  KEY `tiers_groupe_prix` (`groupe_prix_id`),
  CONSTRAINT `fk_tiers_groupe` FOREIGN KEY (`groupe_id`) REFERENCES `groupe_client` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tiers_groupe_prix` FOREIGN KEY (`groupe_prix_id`) REFERENCES `groupe_prix` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ catalogue

CREATE TABLE IF NOT EXISTS `categorie` (
  `id`         VARCHAR(30)  NOT NULL,
  `nom`        VARCHAR(191) NOT NULL,
  `parent_id`  VARCHAR(30)  NULL,
  `created_at` DATETIME(3)  NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categorie_nom` (`nom`),
  KEY `categorie_parent` (`parent_id`),
  CONSTRAINT `fk_categorie_parent` FOREIGN KEY (`parent_id`) REFERENCES `categorie` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marque` (
  `id`         VARCHAR(30)  NOT NULL,
  `nom`        VARCHAR(191) NOT NULL,
  `created_at` DATETIME(3)  NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marque_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `article` (
  `id`           VARCHAR(30)  NOT NULL,
  `reference`    VARCHAR(191) NOT NULL,
  `code_barre`   VARCHAR(191) NULL,
  `nom`          VARCHAR(191) NOT NULL,
  `description`  TEXT         NULL,
  `type`         ENUM('SIMPLE','VARIABLE') NOT NULL DEFAULT 'SIMPLE',
  `unite`        VARCHAR(64)  NOT NULL DEFAULT 'Pièce',
  -- Imprimerie : largeur du rouleau. Renseignée, elle convertit les mètres
  -- linéaires en m² dans les rapports matière (0 = article qui n'est pas un rouleau).
  `laize`        DECIMAL(10,3) NOT NULL DEFAULT 0,
  -- Rôle de l'article dans l'atelier : SUPPORT (rouleau que l'on consomme),
  -- PRESTATION (ce que l'on vend et qui mange un support), AUCUN (marchandise
  -- ordinaire, invisible dans les écrans de l'imprimerie).
  `type_impression` ENUM('AUCUN','SUPPORT','PRESTATION') NOT NULL DEFAULT 'AUCUN',
  -- Prestation : le support qu'elle consomme, et combien par unité produite
  -- (0 = déduit des dimensions et de la laize du rouleau).
  `support_id`     VARCHAR(30)   NULL,
  `conso_unitaire` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `prix_achat`   DECIMAL(14,2) NOT NULL DEFAULT 0,
  `prix_vente`   DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tva`          DECIMAL(5,2)  NOT NULL DEFAULT 20,
  `stock_alerte` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `gere_stock`   TINYINT(1)   NOT NULL DEFAULT 1,
  `actif`        TINYINT(1)   NOT NULL DEFAULT 1,
  `image_url`    TEXT         NULL,
  `categorie_id` VARCHAR(30)  NULL,
  `marque_id`    VARCHAR(30)  NULL,
  `created_at`   DATETIME(3)  NOT NULL,
  `updated_at`   DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `article_reference` (`reference`),
  UNIQUE KEY `article_code_barre` (`code_barre`),
  KEY `article_nom` (`nom`),
  KEY `article_categorie` (`categorie_id`),
  KEY `article_marque` (`marque_id`),
  KEY `article_support` (`support_id`),
  CONSTRAINT `fk_article_support` FOREIGN KEY (`support_id`) REFERENCES `article` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_article_categorie` FOREIGN KEY (`categorie_id`) REFERENCES `categorie` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_article_marque` FOREIGN KEY (`marque_id`) REFERENCES `marque` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `article_variante` (
  `id`         VARCHAR(30)  NOT NULL,
  `article_id` VARCHAR(30)  NOT NULL,
  `nom`        VARCHAR(191) NOT NULL COMMENT 'Attribut : Taille, Couleur…',
  `valeur`     VARCHAR(191) NOT NULL COMMENT 'Valeur : M, Rouge…',
  `reference`  VARCHAR(191) NOT NULL,
  `code_barre` VARCHAR(191) NULL,
  `prix_vente` DECIMAL(14,2) NULL,
  `prix_achat` DECIMAL(14,2) NULL,
  `actif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME(3)  NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `variante_reference` (`reference`),
  UNIQUE KEY `variante_code_barre` (`code_barre`),
  UNIQUE KEY `variante_attribut` (`article_id`, `nom`, `valeur`),
  CONSTRAINT `fk_variante_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prix_groupe` (
  `id`             VARCHAR(30)   NOT NULL,
  `groupe_prix_id` VARCHAR(30)   NOT NULL,
  `article_id`     VARCHAR(30)   NOT NULL,
  `prix`           DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prix_groupe_article` (`groupe_prix_id`, `article_id`),
  KEY `prix_groupe_art` (`article_id`),
  CONSTRAINT `fk_pg_groupe` FOREIGN KEY (`groupe_prix_id`) REFERENCES `groupe_prix` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pg_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- stock

CREATE TABLE IF NOT EXISTS `stock` (
  `id`          VARCHAR(30)   NOT NULL,
  `article_id`  VARCHAR(30)   NOT NULL,
  `variante_id` VARCHAR(30)   NULL,
  `lieu_id`     VARCHAR(30)   NOT NULL,
  `quantite`    DECIMAL(14,3) NOT NULL DEFAULT 0,
  `updated_at`  DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  -- MySQL, comme PostgreSQL, considère deux NULL comme distincts : cet index
  -- ne protège pas le cas « article sans variante ». Les lectures somment donc
  -- les lignes (quantite_stock) pour rester justes en cas de doublon.
  UNIQUE KEY `stock_emplacement` (`article_id`, `variante_id`, `lieu_id`),
  KEY `stock_variante` (`variante_id`),
  KEY `stock_lieu` (`lieu_id`),
  CONSTRAINT `fk_stock_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_variante` FOREIGN KEY (`variante_id`) REFERENCES `article_variante` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- documents commerciaux

CREATE TABLE IF NOT EXISTS `document` (
  `id`              VARCHAR(30)  NOT NULL,
  `numero`          VARCHAR(64)  NOT NULL,
  `type`            ENUM('DEVIS','COMMANDE_CLIENT','BON_LIVRAISON','FACTURE','AVOIR','BON_COMMANDE','ACHAT','RETOUR_ACHAT') NOT NULL,
  `source`          ENUM('BACK_OFFICE','POS') NOT NULL DEFAULT 'BACK_OFFICE',
  `date`            DATETIME(3)  NOT NULL,
  `date_echeance`   DATETIME(3)  NULL,
  `tiers_id`        VARCHAR(30)  NULL,
  `lieu_id`         VARCHAR(30)  NOT NULL,
  `user_id`         VARCHAR(30)  NULL,
  `statut`          ENUM('BROUILLON','VALIDE','CONVERTI','LIVRE','CLOTURE','ANNULE') NOT NULL DEFAULT 'VALIDE',
  `statut_paiement` ENUM('IMPAYE','PARTIEL','PAYE') NOT NULL DEFAULT 'IMPAYE',
  `devise`          CHAR(3)      NOT NULL DEFAULT 'MAD',
  `mode_reglement`  VARCHAR(16)  NULL,
  `reference`       VARCHAR(191) NULL,
  `notes`           TEXT         NULL,
  `total_ht`        DECIMAL(14,2) NOT NULL DEFAULT 0,
  `remise`          DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_tva`       DECIMAL(14,2) NOT NULL DEFAULT 0,
  `frais_port`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_ttc`       DECIMAL(14,2) NOT NULL DEFAULT 0,
  `montant_paye`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `parent_id`       VARCHAR(30)  NULL,
  `created_at`      DATETIME(3)  NOT NULL,
  `updated_at`      DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_numero` (`numero`),
  KEY `document_type_date` (`type`, `date`),
  KEY `document_tiers` (`tiers_id`),
  KEY `document_lieu` (`lieu_id`),
  KEY `document_user` (`user_id`),
  KEY `document_parent` (`parent_id`),
  CONSTRAINT `fk_document_tiers` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_document_parent` FOREIGN KEY (`parent_id`) REFERENCES `document` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ligne_document` (
  `id`            VARCHAR(30)   NOT NULL,
  `document_id`   VARCHAR(30)   NOT NULL,
  `article_id`    VARCHAR(30)   NULL,
  `variante_id`   VARCHAR(30)   NULL,
  `designation`   VARCHAR(255)  NOT NULL,
  `quantite`      DECIMAL(14,3) NOT NULL,
  `prix_unitaire` DECIMAL(14,2) NOT NULL,
  `remise`        DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `tva`           DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `total_ht`      DECIMAL(14,2) NOT NULL,
  `total_ttc`     DECIMAL(14,2) NOT NULL,
  `ordre`         INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ligne_document_doc` (`document_id`),
  KEY `ligne_document_article` (`article_id`),
  KEY `ligne_document_variante` (`variante_id`),
  CONSTRAINT `fk_ligne_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ligne_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ligne_variante` FOREIGN KEY (`variante_id`) REFERENCES `article_variante` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `paiement` (
  `id`          VARCHAR(30)   NOT NULL,
  `document_id` VARCHAR(30)   NOT NULL,
  `date`        DATETIME(3)   NOT NULL,
  `montant`     DECIMAL(14,2) NOT NULL,
  `mode`        ENUM('ESPECES','CHEQUE','EFFET','VIREMENT','CARTE') NOT NULL DEFAULT 'ESPECES',
  `reference`   VARCHAR(191)  NULL,
  `note`        TEXT          NULL,
  `user_id`     VARCHAR(30)   NULL,
  `created_at`  DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `paiement_document` (`document_id`),
  KEY `paiement_user` (`user_id`),
  CONSTRAINT `fk_paiement_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_paiement_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- chèques / effets

CREATE TABLE IF NOT EXISTS `effet` (
  `id`                VARCHAR(30)   NOT NULL,
  `type`              ENUM('CHEQUE','EFFET') NOT NULL DEFAULT 'CHEQUE',
  `sens`              ENUM('RECU','EMIS')    NOT NULL DEFAULT 'RECU',
  `numero`            VARCHAR(191)  NOT NULL,
  `banque`            VARCHAR(191)  NULL,
  `montant`           DECIMAL(14,2) NOT NULL,
  `date_emission`     DATETIME(3)   NOT NULL,
  `date_echeance`     DATETIME(3)   NOT NULL,
  `date_encaissement` DATETIME(3)   NULL,
  `statut`            ENUM('EN_ATTENTE','ENCAISSE','REJETE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE',
  `tiers_id`          VARCHAR(30)   NULL,
  `document_id`       VARCHAR(30)   NULL,
  `paiement_id`       VARCHAR(30)   NULL,
  `note`              TEXT          NULL,
  `created_at`        DATETIME(3)   NOT NULL,
  `updated_at`        DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `effet_paiement` (`paiement_id`),
  KEY `effet_statut_echeance` (`statut`, `date_echeance`),
  KEY `effet_tiers` (`tiers_id`),
  KEY `effet_document` (`document_id`),
  CONSTRAINT `fk_effet_tiers` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_effet_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_effet_paiement` FOREIGN KEY (`paiement_id`) REFERENCES `paiement` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ mouvements stock

CREATE TABLE IF NOT EXISTS `mouvement_stock` (
  `id`          VARCHAR(30)   NOT NULL,
  `article_id`  VARCHAR(30)   NOT NULL,
  `variante_id` VARCHAR(30)   NULL,
  `lieu_id`     VARCHAR(30)   NOT NULL,
  -- PRODUCTION : matière passée dans une machine · CHUTE : matière perdue.
  `type`        ENUM('ACHAT','VENTE','RETOUR_CLIENT','RETOUR_FOURNISSEUR','INVENTAIRE','TRANSFERT','AJUSTEMENT','PRODUCTION','CHUTE','CONSOMMATION') NOT NULL,
  `quantite`    DECIMAL(14,3) NOT NULL,
  `stock_apres` DECIMAL(14,3) NOT NULL,
  `reference`   VARCHAR(191)  NULL,
  `document_id` VARCHAR(30)   NULL,
  -- Sortie d'atelier : la consommation qui a produit le mouvement. Pas de clé
  -- étrangère — `consommation` est créée plus bas, et la reprise se fait à la main.
  `consommation_id` VARCHAR(30) NULL,
  -- Consommable déclaré depuis les dépenses (papier, toner…). Même raison
  -- que ci-dessus : `depense` est créée plus bas, la reprise se fait à la main.
  `depense_id`  VARCHAR(30)   NULL,
  `note`        TEXT          NULL,
  `user_id`     VARCHAR(30)   NULL,
  `created_at`  DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mouvement_article_date` (`article_id`, `created_at`),
  KEY `mouvement_variante` (`variante_id`),
  KEY `mouvement_lieu` (`lieu_id`),
  KEY `mouvement_document` (`document_id`),
  KEY `mouvement_consommation` (`consommation_id`),
  KEY `mouvement_depense` (`depense_id`),
  KEY `mouvement_user` (`user_id`),
  CONSTRAINT `fk_mvt_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mvt_variante` FOREIGN KEY (`variante_id`) REFERENCES `article_variante` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mvt_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mvt_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mvt_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- dépenses

CREATE TABLE IF NOT EXISTS `categorie_depense` (
  `id`         VARCHAR(30)  NOT NULL,
  `nom`        VARCHAR(191) NOT NULL,
  `parent_id`  VARCHAR(30)  NULL,
  `created_at` DATETIME(3)  NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categorie_depense_nom` (`nom`),
  KEY `categorie_depense_parent` (`parent_id`),
  CONSTRAINT `fk_catdep_parent` FOREIGN KEY (`parent_id`) REFERENCES `categorie_depense` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `depense` (
  `id`              VARCHAR(30)   NOT NULL,
  `numero`          VARCHAR(64)   NOT NULL,
  `date`            DATETIME(3)   NOT NULL,
  `categorie_id`    VARCHAR(30)   NULL,
  `lieu_id`         VARCHAR(30)   NOT NULL,
  `user_id`         VARCHAR(30)   NULL,
  `designation`     VARCHAR(255)  NOT NULL,
  `montant_ht`      DECIMAL(14,2) NOT NULL,
  `tva`             DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `montant_ttc`     DECIMAL(14,2) NOT NULL,
  `montant_paye`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `statut_paiement` ENUM('IMPAYE','PARTIEL','PAYE') NOT NULL DEFAULT 'IMPAYE',
  `mode`            ENUM('ESPECES','CHEQUE','EFFET','VIREMENT','CARTE') NOT NULL DEFAULT 'ESPECES',
  `recurrente`      TINYINT(1)    NOT NULL DEFAULT 0,
  -- SAISIE : dépense tapée à la main · CONSOMMATION : sortie de consommables
  -- (papier, toner…) déclarée depuis le stock. Les deux vivent dans la même table
  -- mais ne se totalisent pas ensemble : l'achat est déjà compté ailleurs.
  `origine`         ENUM('SAISIE','CONSOMMATION') NOT NULL DEFAULT 'SAISIE',
  `beneficiaire`    VARCHAR(191)  NULL,
  `note`            TEXT          NULL,
  `created_at`      DATETIME(3)   NOT NULL,
  `updated_at`      DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `depense_numero` (`numero`),
  KEY `depense_categorie` (`categorie_id`),
  KEY `depense_lieu` (`lieu_id`),
  KEY `depense_user` (`user_id`),
  KEY `depense_date` (`date`),
  CONSTRAINT `fk_depense_categorie` FOREIGN KEY (`categorie_id`) REFERENCES `categorie_depense` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_depense_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_depense_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes d'une dépense de consommation : ce qui est sorti du stock, et à quel
-- prix. `prix_achat` est **recopié** au moment de la déclaration, jamais relu depuis
-- la fiche article : changer le prix d'une rame demain ne doit pas réécrire ce
-- qu'on a dépensé le mois dernier.
CREATE TABLE IF NOT EXISTS `depense_ligne` (
  `id`          VARCHAR(30)   NOT NULL,
  `depense_id`  VARCHAR(30)   NOT NULL,
  `article_id`  VARCHAR(30)   NULL,
  `variante_id` VARCHAR(30)   NULL,
  `designation` VARCHAR(255)  NOT NULL,
  `quantite`    DECIMAL(14,3) NOT NULL,
  `prix_achat`  DECIMAL(14,2) NOT NULL COMMENT 'prix d''achat figé à la déclaration',
  `total`       DECIMAL(14,2) NOT NULL,
  `ordre`       INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `depense_ligne_depense` (`depense_id`),
  KEY `depense_ligne_article` (`article_id`),
  KEY `depense_ligne_variante` (`variante_id`),
  CONSTRAINT `fk_depligne_depense` FOREIGN KEY (`depense_id`) REFERENCES `depense` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_depligne_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_depligne_variante` FOREIGN KEY (`variante_id`) REFERENCES `article_variante` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- inventaire physique

CREATE TABLE IF NOT EXISTS `inventaire` (
  `id`         VARCHAR(30)  NOT NULL,
  `numero`     VARCHAR(64)  NOT NULL,
  `date`       DATETIME(3)  NOT NULL,
  `lieu_id`    VARCHAR(30)  NOT NULL,
  `user_id`    VARCHAR(30)  NULL,
  `note`       TEXT         NULL,
  `cloture`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME(3)  NOT NULL,
  `updated_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventaire_numero` (`numero`),
  KEY `inventaire_lieu` (`lieu_id`),
  KEY `inventaire_user` (`user_id`),
  CONSTRAINT `fk_inventaire_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inventaire_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ligne_inventaire` (
  `id`                 VARCHAR(30)   NOT NULL,
  `inventaire_id`      VARCHAR(30)   NOT NULL,
  `article_id`         VARCHAR(30)   NOT NULL,
  `variante_id`        VARCHAR(30)   NULL,
  `quantite_theorique` DECIMAL(14,3) NOT NULL,
  `quantite_reelle`    DECIMAL(14,3) NOT NULL,
  `ecart`              DECIMAL(14,3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ligne_inv_inventaire` (`inventaire_id`),
  KEY `ligne_inv_article` (`article_id`),
  KEY `ligne_inv_variante` (`variante_id`),
  CONSTRAINT `fk_linv_inventaire` FOREIGN KEY (`inventaire_id`) REFERENCES `inventaire` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_linv_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_linv_variante` FOREIGN KEY (`variante_id`) REFERENCES `article_variante` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ catalogue public

CREATE TABLE IF NOT EXISTS `catalogue` (
  `id`            VARCHAR(30)  NOT NULL,
  `slug`          VARCHAR(191) NOT NULL,
  `lieu_id`       VARCHAR(30)  NOT NULL,
  `titre`         VARCHAR(191) NOT NULL,
  `sous_titre`    VARCHAR(191) NULL,
  `actif`           TINYINT(1)   NOT NULL DEFAULT 1,
  `afficher_prix`   TINYINT(1)   NOT NULL DEFAULT 1,
  -- Commande par WhatsApp : le visiteur n'a pas de compte, le message part
  -- vers le telephone du commercant avec l'article deja ecrit.
  `whatsapp`        VARCHAR(32)  NULL,
  `whatsapp_actif`  TINYINT(1)   NOT NULL DEFAULT 0,
  `whatsapp_message` VARCHAR(255) NULL,
  -- Habillage choisi par le commercant : mise en page, teinte, domaine propre.
  `theme`           VARCHAR(16)  NOT NULL DEFAULT 'vitrine',
  `couleur`         VARCHAR(7)   NOT NULL DEFAULT '#1b2153',
  `domaine`         VARCHAR(190) NULL,
  `created_at`      DATETIME(3)  NOT NULL,
  `updated_at`      DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catalogue_slug` (`slug`),
  KEY `catalogue_lieu` (`lieu_id`),
  CONSTRAINT `fk_catalogue_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- livraisons

CREATE TABLE IF NOT EXISTS `livraison` (
  `id`              VARCHAR(30)   NOT NULL,
  `document_id`     VARCHAR(30)   NOT NULL,
  `statut`          ENUM('EN_PREPARATION','EXPEDIE','LIVRE','ANNULE') NOT NULL DEFAULT 'EN_PREPARATION',
  `transporteur`    VARCHAR(191)  NULL,
  `numero_suivi`    VARCHAR(191)  NULL,
  `adresse`         TEXT          NULL,
  `contact`         VARCHAR(191)  NULL,
  `frais_livraison` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `date_expedition` DATETIME(3)   NULL,
  `date_livraison`  DATETIME(3)   NULL,
  `note`            TEXT          NULL,
  `created_at`      DATETIME(3)   NOT NULL,
  `updated_at`      DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `livraison_document` (`document_id`),
  KEY `livraison_statut` (`statut`),
  CONSTRAINT `fk_livraison_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- documentation

CREATE TABLE IF NOT EXISTS `documentation` (
  `id`          VARCHAR(30)  NOT NULL,
  `titre`       VARCHAR(191) NOT NULL,
  `type`        ENUM('NOTE','PROCEDURE','CONTRAT','FICHE_TECHNIQUE','AUTRE') NOT NULL DEFAULT 'NOTE',
  `categorie`   VARCHAR(191) NULL,
  `contenu`     LONGTEXT     NULL,
  `lien_url`    TEXT         NULL,
  `tiers_id`    VARCHAR(30)  NULL,
  `article_id`  VARCHAR(30)  NULL,
  `document_id` VARCHAR(30)  NULL,
  `user_id`     VARCHAR(30)  NULL,
  `created_at`  DATETIME(3)  NOT NULL,
  `updated_at`  DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `documentation_type` (`type`),
  KEY `documentation_titre` (`titre`),
  KEY `documentation_tiers` (`tiers_id`),
  KEY `documentation_article` (`article_id`),
  KEY `documentation_document` (`document_id`),
  KEY `documentation_user` (`user_id`),
  CONSTRAINT `fk_doc_tiers` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------- images
-- Les photos vivent en base, pas sur le disque : la sauvegarde les emporte avec
-- les données et une restauration ailleurs retrouve tout.

CREATE TABLE IF NOT EXISTS `image` (
  `id`         VARCHAR(30)  NOT NULL,
  `nom`        VARCHAR(191) NULL,
  `mime`       VARCHAR(64)  NOT NULL,
  `taille`     INT          NOT NULL,
  `donnees`    LONGBLOB     NOT NULL,
  `created_at` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- numérotation

CREATE TABLE IF NOT EXISTS `sequence` (
  `cle`     VARCHAR(64) NOT NULL,
  `dernier` INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------- journal
-- Journal des ecritures, en ajout seul : qui a change quoi, quand, depuis ou.
-- Aucune ligne n'y est jamais modifiee ni supprimee. C'est la piece qui rend
-- une facture opposable, et le socle de l'archivage sur dix ans.

CREATE TABLE IF NOT EXISTS `evenement` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `objet`      VARCHAR(32)  NOT NULL,
  `objet_id`   VARCHAR(30)  NOT NULL,
  `action`     VARCHAR(32)  NOT NULL,
  `avant`      LONGTEXT     NULL,
  `apres`      LONGTEXT     NULL,
  `user_id`    VARCHAR(30)  NULL,
  `ip`         VARCHAR(45)  NULL,
  `horodatage` DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `evenement_objet` (`objet`, `objet_id`, `id`),
  KEY `evenement_date` (`horodatage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- imprimerie
-- Atelier d'impression. La matière (bâche, vinyle, film DTF, papier) arrive en
-- rouleaux et se consomme au mètre : chaque sortie retire du stock ce qui a
-- servi *et* ce qui est parti en chute, les deux étant journalisés à part pour
-- que le rapport matière puisse les distinguer.

CREATE TABLE IF NOT EXISTS `machine` (
  `id`           VARCHAR(30)   NOT NULL,
  `code`         VARCHAR(64)   NOT NULL,
  `nom`          VARCHAR(191)  NOT NULL,
  `type`         ENUM('IMPRESSION','DTF','SUBLIMATION','LASER','DECOUPE','THERMO','FINITION','AUTRE') NOT NULL DEFAULT 'IMPRESSION',
  `laize`        DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'largeur utile, en mètres',
  `actif`        TINYINT(1)    NOT NULL DEFAULT 1,
  `note`         TEXT          NULL,
  `created_at`   DATETIME(3)   NOT NULL,
  `updated_at`   DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `machine_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordre de travail : le dossier qui suit la commande de l'accueil jusqu'à la
-- livraison. Il ne touche pas au stock — c'est `consommation` qui le fait.
CREATE TABLE IF NOT EXISTS `travail` (
  `id`             VARCHAR(30)   NOT NULL,
  `numero`         VARCHAR(64)   NOT NULL,
  `date`           DATETIME(3)   NOT NULL,
  `date_promise`   DATETIME(3)   NULL,
  `date_livraison` DATETIME(3)   NULL,
  `designation`    VARCHAR(255)  NOT NULL,
  `description`    TEXT          NULL,
  `etape`          ENUM('ACCUEIL','DESIGN','VALIDATION','IMPRESSION','FINITION','PRET','LIVRE','ANNULE') NOT NULL DEFAULT 'ACCUEIL',
  `priorite`       ENUM('NORMALE','URGENTE') NOT NULL DEFAULT 'NORMALE',
  `tiers_id`       VARCHAR(30)   NULL,
  `lieu_id`        VARCHAR(30)   NOT NULL,
  `user_id`        VARCHAR(30)   NULL COMMENT 'accueil : qui a reçu la commande',
  `designer_id`    VARCHAR(30)   NULL,
  `machiniste_id`  VARCHAR(30)   NULL,
  `livreur_id`     VARCHAR(30)   NULL,
  `machine_id`     VARCHAR(30)   NULL,
  `document_id`    VARCHAR(30)   NULL COMMENT 'pièce commerciale tirée du travail',
  `image_url`      TEXT          NULL COMMENT 'aperçu / bon à tirer',
  `contact`        VARCHAR(191)  NULL,
  `remise`         DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_ht`       DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_tva`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_ttc`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `avance`         DECIMAL(14,2) NOT NULL DEFAULT 0,
  `notes`          TEXT          NULL,
  -- Pourquoi le client a renoncé. Obligatoire pour passer un dossier en ANNULE.
  `motif_annulation` VARCHAR(255) NULL,
  -- Sortie automatique de la matière au passage en impression : renseignée,
  -- elle empêche un second prélèvement sur le même dossier.
  `matiere_sortie_le` DATETIME(3) NULL,
  `created_at`     DATETIME(3)   NOT NULL,
  `updated_at`     DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `travail_numero` (`numero`),
  KEY `travail_etape_date` (`etape`, `date`),
  KEY `travail_tiers` (`tiers_id`),
  KEY `travail_lieu` (`lieu_id`),
  KEY `travail_machine` (`machine_id`),
  KEY `travail_document` (`document_id`),
  CONSTRAINT `fk_travail_tiers` FOREIGN KEY (`tiers_id`) REFERENCES `tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travail_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_travail_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travail_designer` FOREIGN KEY (`designer_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travail_machiniste` FOREIGN KEY (`machiniste_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travail_livreur` FOREIGN KEY (`livreur_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travail_machine` FOREIGN KEY (`machine_id`) REFERENCES `machine` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_travail_document` FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une ligne = un article à produire. `mode` dit comment il se compte : au m²
-- (largeur x hauteur x exemplaires), au mètre linéaire (DTF, vinyle) ou à la
-- pièce. `unites` porte le résultat — c'est lui qui est facturé.
CREATE TABLE IF NOT EXISTS `travail_ligne` (
  `id`            VARCHAR(30)   NOT NULL,
  `travail_id`    VARCHAR(30)   NOT NULL,
  `article_id`    VARCHAR(30)   NULL COMMENT 'prestation au catalogue, si elle existe',
  `designation`   VARCHAR(255)  NOT NULL,
  `mode`          ENUM('M2','ML','PIECE') NOT NULL DEFAULT 'M2',
  `largeur`       DECIMAL(10,3) NOT NULL DEFAULT 0,
  `hauteur`       DECIMAL(10,3) NOT NULL DEFAULT 0,
  `quantite`      DECIMAL(14,3) NOT NULL DEFAULT 1 COMMENT 'nombre d exemplaires',
  `unites`        DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'm2, mètres ou pièces facturés',
  -- Support prélevé pour cette ligne, et ce qu'il faut en sortir.
  `support_id`    VARCHAR(30)   NULL,
  `conso_prevue`  DECIMAL(14,3) NOT NULL DEFAULT 0,
  `prix_unitaire` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `remise`        DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `tva`           DECIMAL(5,2)  NOT NULL DEFAULT 0,
  `total_ht`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_ttc`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `ordre`         INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `travail_ligne_travail` (`travail_id`),
  KEY `travail_ligne_article` (`article_id`),
  KEY `travail_ligne_support` (`support_id`),
  CONSTRAINT `fk_tligne_support` FOREIGN KEY (`support_id`) REFERENCES `article` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tligne_travail` FOREIGN KEY (`travail_id`) REFERENCES `travail` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tligne_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal du suivi : une ligne par passage d'étape. C'est lui qui dit qui a
-- fait quoi, quand, et combien de temps le dossier est resté à chaque poste.
CREATE TABLE IF NOT EXISTS `travail_etape` (
  `id`         VARCHAR(30) NOT NULL,
  `travail_id` VARCHAR(30) NOT NULL,
  `etape`      ENUM('ACCUEIL','DESIGN','VALIDATION','IMPRESSION','FINITION','PRET','LIVRE','ANNULE') NOT NULL,
  `user_id`    VARCHAR(30) NULL,
  `note`       TEXT        NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `travail_etape_travail` (`travail_id`, `created_at`),
  KEY `travail_etape_user` (`user_id`),
  CONSTRAINT `fk_tetape_travail` FOREIGN KEY (`travail_id`) REFERENCES `travail` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tetape_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sortie de matière. `quantite` est ce qui a servi, `chute` ce qui est perdu ;
-- le stock retire la somme des deux, en deux mouvements distincts.
CREATE TABLE IF NOT EXISTS `consommation` (
  `id`          VARCHAR(30)   NOT NULL,
  `travail_id`  VARCHAR(30)   NULL,
  `article_id`  VARCHAR(30)   NOT NULL,
  `variante_id` VARCHAR(30)   NULL,
  `lieu_id`     VARCHAR(30)   NOT NULL,
  `machine_id`  VARCHAR(30)   NULL,
  `user_id`     VARCHAR(30)   NULL,
  `date`        DATETIME(3)   NOT NULL,
  `quantite`    DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'matière utilisée',
  `chute`       DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'matière perdue',
  `motif`       ENUM('PRODUCTION','ERREUR_IMPRESSION','ERREUR_DESIGN','BOURRAGE','FIN_ROULEAU','TEST_MACHINE','ANNULATION_CLIENT','AUTRE') NOT NULL DEFAULT 'PRODUCTION',
  `note`        TEXT          NULL,
  `created_at`  DATETIME(3)   NOT NULL,
  `updated_at`  DATETIME(3)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `consommation_date` (`date`),
  KEY `consommation_travail` (`travail_id`),
  KEY `consommation_article` (`article_id`),
  KEY `consommation_lieu` (`lieu_id`),
  KEY `consommation_machine` (`machine_id`),
  KEY `consommation_user` (`user_id`),
  CONSTRAINT `fk_conso_travail` FOREIGN KEY (`travail_id`) REFERENCES `travail` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conso_article` FOREIGN KEY (`article_id`) REFERENCES `article` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conso_variante` FOREIGN KEY (`variante_id`) REFERENCES `article_variante` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conso_lieu` FOREIGN KEY (`lieu_id`) REFERENCES `lieu_affaire` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conso_machine` FOREIGN KEY (`machine_id`) REFERENCES `machine` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conso_user` FOREIGN KEY (`user_id`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
