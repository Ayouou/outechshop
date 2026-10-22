-- ---------------------------------------------------------------------------
-- OUTECH — données minimales de mise en service
--
-- À importer depuis phpMyAdmin APRÈS `outech.sql`, si l'on préfère éviter la page
-- /installation : société, lieu d'affaires et compte administrateur, sans
-- aucune donnée d'exploitation.
--
--   Identifiant : admin
--   Mot de passe : admin123   ← À CHANGER dès la première connexion
--                              (Les utilisateurs → Modifier)
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- --------------------------------------------------------------------- société
INSERT INTO `societe`
  (`id`, `nom`, `devise`, `tva_defaut`, `created_at`, `updated_at`)
VALUES
  ('ste00000000000000000001', 'Ma Société', 'dhs', 20, NOW(3), NOW(3));

-- ------------------------------------------------------------- lieu d'affaires
INSERT INTO `lieu_affaire`
  (`id`, `code`, `nom`, `ville`, `actif`, `created_at`, `updated_at`)
VALUES
  ('lie00000000000000000001', 'PRINCIPAL', 'Magasin principal', NULL, 1, NOW(3), NOW(3));

-- ------------------------------------------------------------------ utilisateur
-- Empreinte bcrypt du mot de passe « admin123 ».
INSERT INTO `utilisateur`
  (`id`, `nom`, `username`, `mot_de_passe`, `role`, `actif`, `created_at`, `updated_at`)
VALUES
  ('usr00000000000000000001', 'Administrateur', 'admin',
   '$2y$10$nqMWV4KCFLDnw4ph4s2fBOQVoDlQlwDV1iLVUypBfD5.WYuYCi2gG',
   'ADMIN', 1, NOW(3), NOW(3));

INSERT INTO `utilisateur_lieu` (`user_id`, `lieu_id`)
VALUES ('usr00000000000000000001', 'lie00000000000000000001');
