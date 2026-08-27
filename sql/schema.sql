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
  date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
