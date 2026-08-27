-- Schéma MySQL — OrTra Suisse de l'Événementiel
-- À importer via phpMyAdmin (hPanel > Bases de données MySQL > phpMyAdmin)
-- sur la base créée pour le site oda-event.ch.

CREATE TABLE IF NOT EXISTS membres_inscription (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_membre ENUM('organisateur','prestataire','travailleur') NOT NULL,
  nom_complet VARCHAR(150) NOT NULL,
  entreprise VARCHAR(150) NULL,
  email VARCHAR(150) NOT NULL,
  telephone VARCHAR(50) NULL,
  canton VARCHAR(50) NOT NULL,
  message TEXT NULL,
  consentement_rgpd TINYINT(1) NOT NULL DEFAULT 0,
  date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP,
  -- Option "profiter gratuitement de la formule Silver sur event-swiss.com"
  event_swiss_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  account_type ENUM('private','company') NULL,
  profile_type ENUM('talent','provider','event') NULL,
  prenom VARCHAR(100) NULL,
  nom VARCHAR(100) NULL,
  es_legal_name VARCHAR(150) NULL,
  es_ide_number VARCHAR(50) NULL,
  es_entity_type ENUM('association','sarl','sa','fondation','individuel','autre') NULL,
  es_legal_representative VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si la table membres_inscription existe déjà en production (déploiement
-- initial déjà fait), exécuter plutôt ces ALTER TABLE via phpMyAdmin :
--
-- ALTER TABLE membres_inscription
--   ADD COLUMN event_swiss_opt_in TINYINT(1) NOT NULL DEFAULT 0,
--   ADD COLUMN account_type ENUM('private','company') NULL,
--   ADD COLUMN profile_type ENUM('talent','provider','event') NULL,
--   ADD COLUMN prenom VARCHAR(100) NULL,
--   ADD COLUMN nom VARCHAR(100) NULL,
--   ADD COLUMN es_legal_name VARCHAR(150) NULL,
--   ADD COLUMN es_ide_number VARCHAR(50) NULL,
--   ADD COLUMN es_entity_type ENUM('association','sarl','sa','fondation','individuel','autre') NULL,
--   ADD COLUMN es_legal_representative VARCHAR(150) NULL;
