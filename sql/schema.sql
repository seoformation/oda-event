-- Schéma MySQL — OrTra Suisse de l'Événementiel
-- À importer via phpMyAdmin (hPanel > Bases de données MySQL > phpMyAdmin)
-- sur la base créée pour le site oda-event.ch.

CREATE TABLE IF NOT EXISTS membres_inscription (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_membre ENUM('organisateur','prestataire','travailleur') NOT NULL,
  nom_complet VARCHAR(150) NOT NULL,
  entreprise VARCHAR(150) NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  telephone VARCHAR(50) NULL,
  canton VARCHAR(50) NOT NULL,
  message TEXT NULL,
  consentement_rgpd TINYINT(1) NOT NULL DEFAULT 0,
  date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP,
  lang VARCHAR(2) NOT NULL DEFAULT 'fr',
  -- Option "profiter gratuitement de la formule Silver sur event-swiss.com"
  event_swiss_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  -- account_type/profile_type : depuis la v2, ce choix (Privé/Entreprise +
  -- profil) remplace aussi l'ancien "Vous êtes organisateur/prestataire/
  -- travailleur" — toujours renseigné, plus seulement si Silver coché.
  account_type ENUM('private','company') NULL,
  profile_type ENUM('talent','provider','event') NULL,
  prenom VARCHAR(100) NULL,
  nom VARCHAR(100) NULL,
  es_address VARCHAR(255) NULL,
  es_legal_name VARCHAR(150) NULL,
  es_ide_number VARCHAR(50) NULL,
  es_entity_type ENUM('association','sarl','sa','fondation','individuel','autre') NULL,
  es_legal_representative VARCHAR(150) NULL,
  es_company_address VARCHAR(255) NULL,
  es_company_email VARCHAR(150) NULL,
  es_company_phone VARCHAR(50) NULL,
  -- Paiement de la cotisation (paiement.php / paiement-webhook.php)
  payment_token VARCHAR(64) NULL UNIQUE,
  paiement_statut ENUM('non_paye','paye') NOT NULL DEFAULT 'non_paye',
  paiement_montant_chf INT NULL,
  paiement_stripe_session_id VARCHAR(255) NULL,
  paiement_confirme_at DATETIME NULL,
  facture_numero VARCHAR(50) NULL,
  -- Compte membre oda-event.ch (mon-compte.php / connexion.php)
  password_hash VARCHAR(255) NULL,
  statut_admission ENUM('en_attente','accepte','refuse') NOT NULL DEFAULT 'en_attente',
  -- Renseigné via statut-webhook.php (appelé par event-swiss.com à
  -- l'acceptation) : indique si un compte event-swiss.com a bien été créé
  -- pour ce membre, pour proposer la synchronisation de profil.
  event_swiss_account_linked TINYINT(1) NOT NULL DEFAULT 0,
  login_tentatives INT NOT NULL DEFAULT 0,
  login_verrouille_jusqu_a DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comptes de l'équipe OrTra (admin.php) : distincts des comptes membres,
-- créés manuellement en base pour l'instant (pas d'auto-inscription admin).
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nom VARCHAR(150) NOT NULL,
  login_tentatives INT NOT NULL DEFAULT 0,
  login_verrouille_jusqu_a DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prochains événements de l'association, gérés depuis admin.php et
-- affichés aux membres connectés dans mon-compte.php.
CREATE TABLE IF NOT EXISTS evenements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titre VARCHAR(200) NOT NULL,
  description TEXT NULL,
  date_debut DATETIME NOT NULL,
  date_fin DATETIME NULL,
  lieu VARCHAR(255) NULL,
  publie TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messagerie à sens unique (admin -> membre(s)), consultée dans
-- mon-compte.php. membre_id NULL = message diffusé à tous les membres.
CREATE TABLE IF NOT EXISTS messages_membres (
  id INT AUTO_INCREMENT PRIMARY KEY,
  membre_id INT NULL,
  titre VARCHAR(200) NOT NULL,
  corps TEXT NOT NULL,
  envoye_par INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (membre_id) REFERENCES membres_inscription(id) ON DELETE CASCADE,
  FOREIGN KEY (envoye_par) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suivi de lecture par membre (nécessaire pour les messages diffusés à
-- tous, où plusieurs membres partagent la même ligne messages_membres).
CREATE TABLE IF NOT EXISTS messages_lectures (
  message_id INT NOT NULL,
  membre_id INT NOT NULL,
  lu_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, membre_id),
  FOREIGN KEY (message_id) REFERENCES messages_membres(id) ON DELETE CASCADE,
  FOREIGN KEY (membre_id) REFERENCES membres_inscription(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Toutes les colonnes du CREATE TABLE ci-dessus (y compris lang, es_address,
-- es_company_*, payment_token, paiement_*, password_hash, statut_admission,
-- event_swiss_account_linked, login_tentatives, login_verrouille_jusqu_a) et
-- la contrainte UNIQUE sur email ont été appliquées en production le
-- 2026-08-27, ainsi que les 4 tables admins/evenements/messages_membres/
-- messages_lectures. Le CREATE TABLE ci-dessus reflète donc fidèlement
-- l'état réel de la base — plus besoin d'ALTER TABLE pour ces colonnes.
--
-- ATTENTION : un ancien commentaire ici affirmait à tort que ces colonnes
-- avaient déjà été ajoutées lors d'un déploiement antérieur, alors qu'elles
-- ne l'avaient jamais été — ce qui a cassé silencieusement inscription.php,
-- admin.php et paiement.php en production (erreurs 500 sans trace, PDO
-- "Column not found") jusqu'à ce que ce soit diagnostiqué et corrigé. En cas
-- de doute sur l'état réel d'une colonne en production, vérifier directement
-- via `SHOW COLUMNS FROM membres_inscription;` plutôt que de se fier à un
-- commentaire.
