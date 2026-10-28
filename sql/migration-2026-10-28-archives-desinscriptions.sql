-- Migration 2026-10-28 — Archives (refus, désinscriptions) et désinscription
-- des membres depuis admin.php.
--
-- Uniquement des AJOUTS (colonnes nullables + une table) : aucune donnée
-- existante n'est modifiée ni supprimée. A exécuter dans phpMyAdmin AVANT de
-- mettre en ligne admin.php / admin-functions.php / statut-webhook.php
-- (ces fichiers lisent et écrivent ces colonnes).
--
-- Vérifier d'abord qu'aucune de ces colonnes n'existe déjà :
--   SHOW COLUMNS FROM membres_inscription;

ALTER TABLE membres_inscription
  -- Date du refus (statut-webhook.php / admin.php), pour Archives → Refus.
  ADD COLUMN refuse_at DATETIME NULL,
  -- Désinscription : date de réception de la demande, date d'effet calculée
  -- (fin d'exercice), préavis non respecté, horodatage de l'enregistrement.
  -- desinscription_enregistree_at non NULL = dossier archivé (désinscriptions).
  ADD COLUMN desinscription_demande_le DATE NULL,
  ADD COLUMN desinscription_effective_le DATE NULL,
  ADD COLUMN desinscription_hors_delai TINYINT(1) NULL,
  ADD COLUMN desinscription_enregistree_at DATETIME NULL,
  -- Année supplémentaire due : exercice concerné et suivi due/payée,
  -- distinct de paiement_statut (cotisation d'adhésion initiale).
  ADD COLUMN annee_supp_annee SMALLINT NULL,
  ADD COLUMN annee_supp_statut ENUM('due','paye') NULL,
  ADD COLUMN annee_supp_montant_chf INT NULL,
  ADD COLUMN annee_supp_paye_at DATETIME NULL,
  -- Suivi de l'e-mail de confirmation de désinscription (anti double envoi,
  -- nouvelle tentative possible en cas d'échec).
  ADD COLUMN desinscription_email_statut ENUM('a_envoyer','en_cours','envoye','echec') NULL,
  ADD COLUMN desinscription_email_at DATETIME NULL;

-- Historique des actions de l'équipe sur les dossiers (affiché dans les
-- archives). Pas de clé étrangère sur membre_id : l'historique doit survivre
-- même si un dossier venait à être supprimé.
CREATE TABLE IF NOT EXISTS admin_journal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  membre_id INT NULL,
  action VARCHAR(50) NOT NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_journal_membre (membre_id),
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Renseigne la date de refus des dossiers déjà refusés quand elle est connue
-- ailleurs : aucune source fiable n'existe, on la laisse NULL (affichée « — »).
