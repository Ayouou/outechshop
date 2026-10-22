-- ---------------------------------------------------------------------------
-- OUTECH — données de démonstration
--
-- À importer depuis phpMyAdmin APRÈS `outech.sql`, dans une base vide.
-- Contient une société, deux comptes, un catalogue d'articles (dont un article
-- à variantes), des clients, un fournisseur, quelques documents avec leurs
-- règlements et leurs mouvements de stock, un chèque en attente, une dépense,
-- une fiche de documentation et un catalogue public.
--
-- Comptes :
--   admin  / admin123   (administrateur)
--   caisse / caisse123  (caissier — ouvre directement sur La caisse)
--
-- > Changez ces mots de passe avant toute mise en service.
--
-- Les dates sont relatives au jour de l'import : le tableau de bord et la
-- caisse montrent donc tout de suite quelque chose. Les compteurs de
-- numérotation sont posés en cohérence avec les documents créés : la prochaine
-- facture repartira sur FA<année>/0003.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------- société

INSERT INTO `societe`
  (`id`, `nom`, `ice`, `rc`, `identifiant_fiscal`, `patente`, `cnss`, `adresse`, `ville`,
   `telephone`, `email`, `devise`, `tva_defaut`, `pied_facture`, `created_at`, `updated_at`)
VALUES
  ('ste00000000000000000001', 'Quincaillerie Al Madina', '001234567000045', '54321', '12345678',
   '30123456', '1234567', '145, boulevard Mohammed V', 'Casablanca',
   '0522334455', 'contact@almadina.ma', 'dhs', 20,
   'Merci de votre confiance. Marchandise vendue non reprise après 7 jours.',
   NOW(3), NOW(3));

-- ------------------------------------------------------------- lieux d'affaires

INSERT INTO `lieu_affaire`
  (`id`, `code`, `nom`, `adresse`, `ville`, `telephone`, `actif`, `created_at`, `updated_at`)
VALUES
  ('lie00000000000000000001', 'PRINCIPAL', 'Magasin principal', '145, boulevard Mohammed V',
   'Casablanca', '0522334455', 1, NOW(3), NOW(3)),
  ('lie00000000000000000002', 'DEPOT', 'Dépôt Ain Sebaa', 'Zone industrielle, lot 42',
   'Casablanca', '0522667788', 1, NOW(3), NOW(3));

-- --------------------------------------------------------------- utilisateurs

-- Empreintes bcrypt de « admin123 » et « caisse123 ».
INSERT INTO `utilisateur`
  (`id`, `nom`, `username`, `email`, `mot_de_passe`, `role`, `telephone`, `actif`,
   `created_at`, `updated_at`)
VALUES
  ('usr00000000000000000001', 'Administrateur', 'admin', 'admin@almadina.ma',
   '$2y$10$nqMWV4KCFLDnw4ph4s2fBOQVoDlQlwDV1iLVUypBfD5.WYuYCi2gG',
   'ADMIN', '0661112233', 1, NOW(3), NOW(3)),
  ('usr00000000000000000002', 'Youssef — caisse', 'caisse', NULL,
   '$2y$10$DpX55BMVtAr2yxohkIVotu0Xdy/IurpJVsYuMaY71yhjXeTxg5CYC',
   'CAISSIER', '0662223344', 1, NOW(3), NOW(3));

INSERT INTO `utilisateur_lieu` (`user_id`, `lieu_id`) VALUES
  ('usr00000000000000000001', 'lie00000000000000000001'),
  ('usr00000000000000000001', 'lie00000000000000000002'),
  ('usr00000000000000000002', 'lie00000000000000000001');

-- ---------------------------------------------------------------- groupes

INSERT INTO `groupe_client`
  (`id`, `nom`, `remise_pourcent`, `description`, `created_at`, `updated_at`)
VALUES
  ('gcl00000000000000000001', 'Revendeurs', 5, 'Remise permanente accordée aux revendeurs',
   NOW(3), NOW(3));

INSERT INTO `groupe_prix`
  (`id`, `nom`, `description`, `actif`, `created_at`, `updated_at`)
VALUES
  ('gpx00000000000000000001', 'Grossistes', 'Grille tarifaire réservée aux gros volumes',
   1, NOW(3), NOW(3));

-- ------------------------------------------------------- catégories et marques

INSERT INTO `categorie` (`id`, `nom`, `parent_id`, `created_at`, `updated_at`) VALUES
  ('cat00000000000000000001', 'Matériaux',   NULL, NOW(3), NOW(3)),
  ('cat00000000000000000002', 'Peinture',    NULL, NOW(3), NOW(3)),
  ('cat00000000000000000003', 'Outillage',   NULL, NOW(3), NOW(3)),
  ('cat00000000000000000004', 'Électricité', NULL, NOW(3), NOW(3)),
  ('cat00000000000000000005', 'Vêtements de travail', 'cat00000000000000000003', NOW(3), NOW(3));

INSERT INTO `marque` (`id`, `nom`, `created_at`, `updated_at`) VALUES
  ('mrq00000000000000000001', 'Lafarge', NOW(3), NOW(3)),
  ('mrq00000000000000000002', 'Astral',  NOW(3), NOW(3)),
  ('mrq00000000000000000003', 'Bosch',   NOW(3), NOW(3));

-- ------------------------------------------------------------------- articles

INSERT INTO `article`
  (`id`, `reference`, `code_barre`, `nom`, `description`, `type`, `unite`,
   `prix_achat`, `prix_vente`, `tva`, `stock_alerte`, `gere_stock`, `actif`,
   `categorie_id`, `marque_id`, `created_at`, `updated_at`)
VALUES
  ('art00000000000000000001', 'CIM50', '6111000000011', 'Ciment CPJ 45 — sac de 50 kg',
   'Ciment portland composé, usage général.', 'SIMPLE', 'Sac',
   62.00, 85.00, 20, 20, 1, 1, 'cat00000000000000000001', 'mrq00000000000000000001', NOW(3), NOW(3)),

  ('art00000000000000000002', 'PEI5L', '6111000000028', 'Peinture blanche mate 5 L',
   'Peinture intérieure lessivable.', 'SIMPLE', 'Pièce',
   170.00, 240.00, 20, 5, 1, 1, 'cat00000000000000000002', 'mrq00000000000000000002', NOW(3), NOW(3)),

  ('art00000000000000000003', 'PERC800', '6111000000035', 'Perceuse à percussion 800 W',
   'Mandrin 13 mm, coffret et jeu de forets.', 'SIMPLE', 'Pièce',
   640.00, 890.00, 20, 3, 1, 1, 'cat00000000000000000003', 'mrq00000000000000000003', NOW(3), NOW(3)),

  ('art00000000000000000004', 'CAB25', '6111000000042', 'Câble électrique 2,5 mm² — 100 m',
   NULL, 'SIMPLE', 'Rouleau',
   380.00, 520.00, 20, 5, 1, 1, 'cat00000000000000000004', NULL, NOW(3), NOW(3)),

  ('art00000000000000000005', 'SABLE', NULL, 'Sable de construction',
   'Vendu au kilo, livraison possible.', 'SIMPLE', 'Kg',
   0.90, 1.30, 20, 500, 1, 1, 'cat00000000000000000001', NULL, NOW(3), NOW(3)),

  -- Article à variantes : ce sont les variantes qui portent le stock.
  ('art00000000000000000006', 'COMBI', NULL, 'Combinaison de travail',
   'Coton renforcé, plusieurs tailles.', 'VARIABLE', 'Pièce',
   95.00, 150.00, 20, 3, 1, 1, 'cat00000000000000000005', NULL, NOW(3), NOW(3)),

  -- Prestation non suivie en stock (gere_stock = 0) : jamais bornée à la caisse.
  ('art00000000000000000007', 'LIVR', NULL, 'Livraison sur Casablanca',
   NULL, 'SIMPLE', 'Pièce',
   0.00, 150.00, 20, 0, 0, 1, NULL, NULL, NOW(3), NOW(3));

INSERT INTO `article_variante`
  (`id`, `article_id`, `nom`, `valeur`, `reference`, `code_barre`, `prix_vente`, `prix_achat`,
   `actif`, `created_at`, `updated_at`)
VALUES
  ('var00000000000000000001', 'art00000000000000000006', 'Taille', 'S', 'COMBI-S', NULL, NULL, NULL, 1, NOW(3), NOW(3)),
  ('var00000000000000000002', 'art00000000000000000006', 'Taille', 'M', 'COMBI-M', NULL, NULL, NULL, 1, NOW(3), NOW(3)),
  -- La grande taille coûte un peu plus cher : prix propre, il remplace celui de l'article.
  ('var00000000000000000003', 'art00000000000000000006', 'Taille', 'L', 'COMBI-L', NULL, 165.00, 105.00, 1, NOW(3), NOW(3));

-- Grille tarifaire du groupe « Grossistes ».
INSERT INTO `prix_groupe` (`id`, `groupe_prix_id`, `article_id`, `prix`) VALUES
  ('pgr00000000000000000001', 'gpx00000000000000000001', 'art00000000000000000001', 78.00),
  ('pgr00000000000000000002', 'gpx00000000000000000001', 'art00000000000000000002', 220.00),
  ('pgr00000000000000000003', 'gpx00000000000000000001', 'art00000000000000000004', 480.00);

-- ---------------------------------------------------------------------- tiers

INSERT INTO `tiers`
  (`id`, `code`, `type`, `nom`, `nom_entreprise`, `email`, `telephone`, `mobile`, `ice`, `rc`,
   `adresse`, `ville`, `conditions_paiement`, `solde_ouverture`, `plafond_credit`, `actif`,
   `groupe_id`, `groupe_prix_id`, `created_at`, `updated_at`)
VALUES
  ('trs00000000000000000001', CONCAT('CL', YEAR(NOW()), '0001'), 'CLIENT',
   'Ahmed Benali', 'Benali Travaux', 'benali@example.ma', '0522111222', '0661234567',
   '001234567000045', '78945', 'Résidence Al Fath, imm. 4', 'Casablanca',
   '30 jours fin de mois', 0, 20000, 1, 'gcl00000000000000000001', NULL, NOW(3), NOW(3)),

  ('trs00000000000000000002', CONCAT('CL', YEAR(NOW()), '0002'), 'CLIENT',
   'Fatima Zahra Idrissi', 'Idrissi Distribution', NULL, NULL, '0662345678',
   '002345678000021', NULL, 'Avenue Hassan II, n° 12', 'Rabat',
   'Comptant', 0, 50000, 1, NULL, 'gpx00000000000000000001', NOW(3), NOW(3)),

  ('trs00000000000000000003', CONCAT('CL', YEAR(NOW()), '0003'), 'CLIENT',
   'Karim Alaoui', NULL, NULL, NULL, '0663456789', NULL, NULL, NULL, 'Casablanca',
   NULL, 0, 0, 1, NULL, NULL, NOW(3), NOW(3)),

  ('trs00000000000000000004', CONCAT('FR', YEAR(NOW()), '0001'), 'FOURNISSEUR',
   'Comptoir des Matériaux', 'CDM SARL', 'achats@cdm.ma', '0522667788', NULL,
   '000987654000032', '11223', 'Zone industrielle Sidi Bernoussi', 'Casablanca',
   '45 jours', 0, 0, 1, NULL, NULL, NOW(3), NOW(3)),

  ('trs00000000000000000005', CONCAT('FR', YEAR(NOW()), '0002'), 'LES_DEUX',
   'Ets Rachidi', 'Rachidi & Fils', NULL, '0537445566', NULL, NULL, NULL,
   NULL, 'Kénitra', NULL, 0, 10000, 1, NULL, NULL, NOW(3), NOW(3));

-- ------------------------------------------------------------- stock de départ

INSERT INTO `stock` (`id`, `article_id`, `variante_id`, `lieu_id`, `quantite`, `updated_at`) VALUES
  ('stk00000000000000000001', 'art00000000000000000001', NULL, 'lie00000000000000000001', 320.000, NOW(3)),
  ('stk00000000000000000002', 'art00000000000000000002', NULL, 'lie00000000000000000001',  43.000, NOW(3)),
  ('stk00000000000000000003', 'art00000000000000000003', NULL, 'lie00000000000000000001',  11.000, NOW(3)),
  ('stk00000000000000000004', 'art00000000000000000004', NULL, 'lie00000000000000000001',  27.000, NOW(3)),
  ('stk00000000000000000005', 'art00000000000000000005', NULL, 'lie00000000000000000001', 5000.000, NOW(3)),
  ('stk00000000000000000006', 'art00000000000000000006', 'var00000000000000000001', 'lie00000000000000000001', 10.000, NOW(3)),
  ('stk00000000000000000007', 'art00000000000000000006', 'var00000000000000000002', 'lie00000000000000000001', 15.000, NOW(3)),
  ('stk00000000000000000008', 'art00000000000000000006', 'var00000000000000000003', 'lie00000000000000000001',  8.000, NOW(3)),
  -- Un peu de stock au dépôt, pour illustrer le suivi par lieu.
  ('stk00000000000000000009', 'art00000000000000000001', NULL, 'lie00000000000000000002', 150.000, NOW(3));

-- --------------------------------------------------------------------- documents

-- Achat au fournisseur, non réglé (échéance dans 23 jours).
INSERT INTO `document`
  (`id`, `numero`, `type`, `source`, `date`, `date_echeance`, `tiers_id`, `lieu_id`, `user_id`,
   `statut`, `statut_paiement`, `reference`, `notes`,
   `total_ht`, `remise`, `total_tva`, `frais_port`, `total_ttc`, `montant_paye`,
   `parent_id`, `created_at`, `updated_at`)
VALUES
  ('doc00000000000000000001', CONCAT('AC', YEAR(NOW()), '/0001'), 'ACHAT', 'BACK_OFFICE',
   NOW(3) - INTERVAL 7 DAY, NOW(3) + INTERVAL 23 DAY,
   'trs00000000000000000004', 'lie00000000000000000001', 'usr00000000000000000001',
   'VALIDE', 'IMPAYE', 'BL-CDM-8841', 'Réapprovisionnement ciment.',
   6200.00, 0, 1240.00, 0, 7440.00, 0, NULL, NOW(3) - INTERVAL 7 DAY, NOW(3) - INTERVAL 7 DAY),

-- Facture client réglée en espèces.
  ('doc00000000000000000002', CONCAT('FA', YEAR(NOW()), '/0001'), 'FACTURE', 'BACK_OFFICE',
   NOW(3) - INTERVAL 5 DAY, NULL,
   'trs00000000000000000001', 'lie00000000000000000001', 'usr00000000000000000001',
   'VALIDE', 'PAYE', NULL, NULL,
   2180.00, 0, 436.00, 0, 2616.00, 2616.00, NULL, NOW(3) - INTERVAL 5 DAY, NOW(3) - INTERVAL 5 DAY),

-- Vente au comptoir : bon de livraison encaissé, en attente de regroupement.
  ('doc00000000000000000003', CONCAT('BL', YEAR(NOW()), '/0001'), 'BON_LIVRAISON', 'POS',
   NOW(3) - INTERVAL 2 DAY, NULL,
   NULL, 'lie00000000000000000001', 'usr00000000000000000002',
   'VALIDE', 'PAYE', NULL, NULL,
   890.00, 0, 178.00, 0, 1068.00, 1068.00, NULL, NOW(3) - INTERVAL 2 DAY, NOW(3) - INTERVAL 2 DAY),

-- Facture au tarif « Grossistes », réglée en partie par chèque.
  ('doc00000000000000000004', CONCAT('FA', YEAR(NOW()), '/0002'), 'FACTURE', 'BACK_OFFICE',
   NOW(3) - INTERVAL 1 DAY, NOW(3) + INTERVAL 29 DAY,
   'trs00000000000000000002', 'lie00000000000000000001', 'usr00000000000000000001',
   'VALIDE', 'PARTIEL', NULL, 'Livraison prévue en fin de semaine.',
   1440.00, 0, 288.00, 0, 1728.00, 500.00, NULL, NOW(3) - INTERVAL 1 DAY, NOW(3) - INTERVAL 1 DAY);

INSERT INTO `ligne_document`
  (`id`, `document_id`, `article_id`, `variante_id`, `designation`,
   `quantite`, `prix_unitaire`, `remise`, `tva`, `total_ht`, `total_ttc`, `ordre`)
VALUES
  ('lgd00000000000000000001', 'doc00000000000000000001', 'art00000000000000000001', NULL,
   'Ciment CPJ 45 — sac de 50 kg', 100.000, 62.00, 0, 20, 6200.00, 7440.00, 0),

  ('lgd00000000000000000002', 'doc00000000000000000002', 'art00000000000000000001', NULL,
   'Ciment CPJ 45 — sac de 50 kg', 20.000, 85.00, 0, 20, 1700.00, 2040.00, 0),
  ('lgd00000000000000000003', 'doc00000000000000000002', 'art00000000000000000002', NULL,
   'Peinture blanche mate 5 L', 2.000, 240.00, 0, 20, 480.00, 576.00, 1),

  ('lgd00000000000000000004', 'doc00000000000000000003', 'art00000000000000000003', NULL,
   'Perceuse à percussion 800 W', 1.000, 890.00, 0, 20, 890.00, 1068.00, 0),

  ('lgd00000000000000000005', 'doc00000000000000000004', 'art00000000000000000004', NULL,
   'Câble électrique 2,5 mm² — 100 m', 3.000, 480.00, 0, 20, 1440.00, 1728.00, 0);

INSERT INTO `paiement`
  (`id`, `document_id`, `date`, `montant`, `mode`, `reference`, `note`, `user_id`, `created_at`)
VALUES
  ('pmt00000000000000000001', 'doc00000000000000000002', NOW(3) - INTERVAL 5 DAY,
   2616.00, 'ESPECES', NULL, NULL, 'usr00000000000000000001', NOW(3) - INTERVAL 5 DAY),
  ('pmt00000000000000000002', 'doc00000000000000000003', NOW(3) - INTERVAL 2 DAY,
   1068.00, 'CARTE', NULL, 'Encaissement au comptoir', 'usr00000000000000000002', NOW(3) - INTERVAL 2 DAY),
  ('pmt00000000000000000003', 'doc00000000000000000004', NOW(3) - INTERVAL 1 DAY,
   500.00, 'CHEQUE', '4471203', NULL, 'usr00000000000000000001', NOW(3) - INTERVAL 1 DAY);

-- Le règlement par chèque crée sa pièce dans le module Chèques / Effets.
INSERT INTO `effet`
  (`id`, `type`, `sens`, `numero`, `banque`, `montant`, `date_emission`, `date_echeance`,
   `date_encaissement`, `statut`, `tiers_id`, `document_id`, `paiement_id`, `note`,
   `created_at`, `updated_at`)
VALUES
  ('eff00000000000000000001', 'CHEQUE', 'RECU', '4471203', 'Attijariwafa Bank', 500.00,
   NOW(3) - INTERVAL 1 DAY, NOW(3) + INTERVAL 20 DAY, NULL, 'EN_ATTENTE',
   'trs00000000000000000002', 'doc00000000000000000004', 'pmt00000000000000000003', NULL,
   NOW(3) - INTERVAL 1 DAY, NOW(3) - INTERVAL 1 DAY),

  ('eff00000000000000000002', 'EFFET', 'EMIS', 'LCR-2291', 'Banque Populaire', 7440.00,
   NOW(3) - INTERVAL 7 DAY, NOW(3) + INTERVAL 23 DAY, NULL, 'EN_ATTENTE',
   'trs00000000000000000004', 'doc00000000000000000001', NULL, 'Traite acceptée pour le réassort.',
   NOW(3) - INTERVAL 7 DAY, NOW(3) - INTERVAL 7 DAY);

-- ------------------------------------------------------- mouvements de stock
-- Chronologie : stock initial, puis chaque document dans l'ordre de sa date.
-- `stock_apres` suit cette chronologie et retombe sur les quantités de `stock`.

INSERT INTO `mouvement_stock`
  (`id`, `article_id`, `variante_id`, `lieu_id`, `type`, `quantite`, `stock_apres`,
   `reference`, `document_id`, `note`, `user_id`, `created_at`)
VALUES
  ('mvt00000000000000000001', 'art00000000000000000001', NULL, 'lie00000000000000000001',
   'AJUSTEMENT',  240.000,  240.000, NULL, NULL, 'Stock initial', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000002', 'art00000000000000000002', NULL, 'lie00000000000000000001',
   'AJUSTEMENT',   45.000,   45.000, NULL, NULL, 'Stock initial', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000003', 'art00000000000000000003', NULL, 'lie00000000000000000001',
   'AJUSTEMENT',   12.000,   12.000, NULL, NULL, 'Stock initial', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000004', 'art00000000000000000004', NULL, 'lie00000000000000000001',
   'AJUSTEMENT',   30.000,   30.000, NULL, NULL, 'Stock initial', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000005', 'art00000000000000000005', NULL, 'lie00000000000000000001',
   'AJUSTEMENT', 5000.000, 5000.000, NULL, NULL, 'Stock initial', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000006', 'art00000000000000000006', 'var00000000000000000001', 'lie00000000000000000001',
   'AJUSTEMENT',   10.000,   10.000, NULL, NULL, 'Stock initial — Taille : S', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000007', 'art00000000000000000006', 'var00000000000000000002', 'lie00000000000000000001',
   'AJUSTEMENT',   15.000,   15.000, NULL, NULL, 'Stock initial — Taille : M', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000008', 'art00000000000000000006', 'var00000000000000000003', 'lie00000000000000000001',
   'AJUSTEMENT',    8.000,    8.000, NULL, NULL, 'Stock initial — Taille : L', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),
  ('mvt00000000000000000009', 'art00000000000000000001', NULL, 'lie00000000000000000002',
   'AJUSTEMENT',  150.000,  150.000, NULL, NULL, 'Stock initial dépôt', 'usr00000000000000000001', NOW(3) - INTERVAL 10 DAY),

  -- Achat : la marchandise entre.
  ('mvt00000000000000000010', 'art00000000000000000001', NULL, 'lie00000000000000000001',
   'ACHAT', 100.000, 340.000, CONCAT('AC', YEAR(NOW()), '/0001'), 'doc00000000000000000001',
   NULL, 'usr00000000000000000001', NOW(3) - INTERVAL 7 DAY),

  -- Facture : la marchandise sort.
  ('mvt00000000000000000011', 'art00000000000000000001', NULL, 'lie00000000000000000001',
   'VENTE', -20.000, 320.000, CONCAT('FA', YEAR(NOW()), '/0001'), 'doc00000000000000000002',
   NULL, 'usr00000000000000000001', NOW(3) - INTERVAL 5 DAY),
  ('mvt00000000000000000012', 'art00000000000000000002', NULL, 'lie00000000000000000001',
   'VENTE',  -2.000,  43.000, CONCAT('FA', YEAR(NOW()), '/0001'), 'doc00000000000000000002',
   NULL, 'usr00000000000000000001', NOW(3) - INTERVAL 5 DAY),

  -- Vente au comptoir.
  ('mvt00000000000000000013', 'art00000000000000000003', NULL, 'lie00000000000000000001',
   'VENTE',  -1.000,  11.000, CONCAT('BL', YEAR(NOW()), '/0001'), 'doc00000000000000000003',
   NULL, 'usr00000000000000000002', NOW(3) - INTERVAL 2 DAY),

  ('mvt00000000000000000014', 'art00000000000000000004', NULL, 'lie00000000000000000001',
   'VENTE',  -3.000,  27.000, CONCAT('FA', YEAR(NOW()), '/0002'), 'doc00000000000000000004',
   NULL, 'usr00000000000000000001', NOW(3) - INTERVAL 1 DAY);

-- ---------------------------------------------------------------------- dépenses

INSERT INTO `categorie_depense` (`id`, `nom`, `parent_id`, `created_at`, `updated_at`) VALUES
  ('cdp00000000000000000001', 'Charges fixes', NULL, NOW(3), NOW(3)),
  ('cdp00000000000000000002', 'Transport',     NULL, NOW(3), NOW(3));

INSERT INTO `depense`
  (`id`, `numero`, `date`, `categorie_id`, `lieu_id`, `user_id`, `designation`,
   `montant_ht`, `tva`, `montant_ttc`, `montant_paye`, `statut_paiement`, `mode`,
   `recurrente`, `beneficiaire`, `note`, `created_at`, `updated_at`)
VALUES
  ('dep00000000000000000001', CONCAT('DP', YEAR(NOW()), '/0001'), NOW(3) - INTERVAL 6 DAY,
   'cdp00000000000000000001', 'lie00000000000000000001', 'usr00000000000000000001',
   'Loyer du magasin', 4000.00, 0, 4000.00, 4000.00, 'PAYE', 'VIREMENT',
   1, 'SCI Al Manar', NULL, NOW(3) - INTERVAL 6 DAY, NOW(3) - INTERVAL 6 DAY),

  ('dep00000000000000000002', CONCAT('DP', YEAR(NOW()), '/0002'), NOW(3) - INTERVAL 3 DAY,
   'cdp00000000000000000002', 'lie00000000000000000001', 'usr00000000000000000001',
   'Carburant camionnette', 500.00, 20, 600.00, 600.00, 'PAYE', 'ESPECES',
   0, 'Station Afriquia', NULL, NOW(3) - INTERVAL 3 DAY, NOW(3) - INTERVAL 3 DAY);

-- ------------------------------------------------------------------- livraison

INSERT INTO `livraison`
  (`id`, `document_id`, `statut`, `transporteur`, `numero_suivi`, `adresse`, `contact`,
   `frais_livraison`, `date_expedition`, `date_livraison`, `note`, `created_at`, `updated_at`)
VALUES
  ('liv00000000000000000001', 'doc00000000000000000003', 'LIVRE', 'Livraison interne', NULL,
   'Résidence Al Fath, imm. 4 — Casablanca', '0661234567', 0,
   NOW(3) - INTERVAL 2 DAY, NOW(3) - INTERVAL 2 DAY, NULL,
   NOW(3) - INTERVAL 2 DAY, NOW(3) - INTERVAL 2 DAY);

-- --------------------------------------------------------------- documentation

INSERT INTO `documentation`
  (`id`, `titre`, `type`, `categorie`, `contenu`, `lien_url`,
   `tiers_id`, `article_id`, `document_id`, `user_id`, `created_at`, `updated_at`)
VALUES
  ('dcm00000000000000000001', 'Procédure de retour marchandise', 'PROCEDURE', 'Ventes',
   '1. Vérifier la facture ou le bon de livraison d''origine.\n2. Contrôler l''état de la marchandise.\n3. Créer un avoir depuis l''historique du client : le formulaire ne propose que ce qui lui a réellement été livré.\n4. Rembourser ou porter au crédit du compte client.',
   NULL, NULL, NULL, NULL, 'usr00000000000000000001', NOW(3), NOW(3)),

  ('dcm00000000000000000002', 'Fiche technique — Ciment CPJ 45', 'FICHE_TECHNIQUE', 'Produits',
   'Résistance à 28 jours : 45 MPa. Conserver au sec, sur palette, 3 mois maximum.',
   NULL, NULL, 'art00000000000000000001', NULL, 'usr00000000000000000001', NOW(3), NOW(3));

-- ------------------------------------------------------------ catalogue public

INSERT INTO `catalogue`
  (`id`, `slug`, `lieu_id`, `titre`, `sous_titre`, `actif`, `afficher_prix`, `created_at`, `updated_at`)
VALUES
  ('ctl00000000000000000001', 'al-madina', 'lie00000000000000000001',
   'Quincaillerie Al Madina', 'Matériaux, peinture, outillage et électricité',
   1, 1, NOW(3), NOW(3));

-- ----------------------------------------------------------------- compteurs
-- Cohérents avec les documents ci-dessus : la prochaine facture sera FA<année>/0003.

INSERT INTO `sequence` (`cle`, `dernier`) VALUES
  (CONCAT('FA-', YEAR(NOW())), 2),
  (CONCAT('BL-', YEAR(NOW())), 1),
  (CONCAT('AC-', YEAR(NOW())), 1),
  (CONCAT('DP-', YEAR(NOW())), 2),
  (CONCAT('CL-', YEAR(NOW())), 3),
  (CONCAT('FR-', YEAR(NOW())), 2);

SET FOREIGN_KEY_CHECKS = 1;
