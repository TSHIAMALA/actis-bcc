-- ============================================================
-- MODELE RELATIONNEL
-- SUIVI DES ORDRES ET INSTRUCTIONS DU GOUVERNEUR
-- Banque Centrale du Congo (BCC)
-- Compatible MySQL 8.x / MariaDB 10.6+
--
-- Version : 2.2
-- Principales évolutions :
--   1. Remplacement de "directions" par une hiérarchie générique
--      types_entite + entites (parent_id)
--   2. Utilisateurs rattachés à une entité
--   3. Instructions pilotées par une entité
--   4. Actions rattachées à une entité responsable
--   5. Gestion des entités contributrices
--   6. Factorisation des statuts/types de relance
--   7. Factorisation des types d'événements d'historique
--   8. Compatibilité MySQL/MariaDB : les contraintes CHECK
--      multi-colonnes portant sur des clés étrangères avec CASCADE/SET NULL
--      ont été volontairement retirées. La règle de cible exclusive
--      (instruction OU action) doit être contrôlée par l'application.
--   9. Les CHECK ont été retirés de cette version pour maximiser la
--      compatibilité entre différentes versions MySQL/MariaDB.
-- ============================================================

CREATE DATABASE IF NOT EXISTS suivi_instructions_bcc
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE suivi_instructions_bcc;

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS v_performance_directions;
DROP VIEW IF EXISTS v_dashboard_instructions;
DROP VIEW IF EXISTS v_actions_en_retard;

DROP TABLE IF EXISTS commentaires;
DROP TABLE IF EXISTS relances;
DROP TABLE IF EXISTS prorogations;
DROP TABLE IF EXISTS historique;
DROP TABLE IF EXISTS justificatifs;
DROP TABLE IF EXISTS instruction_entites;
DROP TABLE IF EXISTS actions;
DROP TABLE IF EXISTS instructions;
DROP TABLE IF EXISTS utilisateur_roles;
DROP TABLE IF EXISTS utilisateurs;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS entites;
DROP TABLE IF EXISTS types_entite;

DROP TABLE IF EXISTS statuts_relance;
DROP TABLE IF EXISTS types_evenement;
DROP TABLE IF EXISTS statuts_prorogation;
DROP TABLE IF EXISTS canaux_notification;
DROP TABLE IF EXISTS types_relance;
DROP TABLE IF EXISTS priorites;
DROP TABLE IF EXISTS statuts;
DROP TABLE IF EXISTS types_instruction;

SET FOREIGN_KEY_CHECKS = 1;

-- IMPORTANT POUR L'IMPORT
-- Ce script crée/recrée la base et ses tables. Il utilise InnoDB.
-- Les contrôles métier (ex. taux 0-100, cible instruction/action,
-- cohérence parent/type d'entité) sont à appliquer dans l'application
-- ACTIS ou via des procédures/triggers si souhaité.
--
-- ============================================================
-- 1. TABLES DE REFERENCE
-- ============================================================

CREATE TABLE types_instruction (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE statuts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    couleur VARCHAR(20) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE priorites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    niveau INT NOT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE types_relance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE statuts_relance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE canaux_notification (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE statuts_prorogation (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE types_evenement (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ============================================================
-- 2. ORGANISATION HIERARCHIQUE
-- ============================================================

CREATE TABLE types_entite (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    niveau INT NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre_affichage INT NOT NULL DEFAULT 0,
    CONSTRAINT chk_type_entite_niveau CHECK (niveau >= 0)
) ENGINE=InnoDB;

CREATE TABLE entites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    nom VARCHAR(255) NOT NULL,
    type_entite_id INT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    description TEXT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_entite_type
        FOREIGN KEY (type_entite_id)
        REFERENCES types_entite(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_entite_parent
        FOREIGN KEY (parent_id)
        REFERENCES entites(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_entite_type (type_entite_id),
    INDEX idx_entite_parent (parent_id),
    INDEX idx_entite_actif (actif)
) ENGINE=InnoDB;

-- ============================================================
-- 3. UTILISATEURS ET ROLES
-- ============================================================

CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE utilisateurs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    matricule VARCHAR(50) NOT NULL UNIQUE,
    nom VARCHAR(100) NOT NULL,
    postnom VARCHAR(100) NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    telephone VARCHAR(50) NULL,
    mot_de_passe_hash VARCHAR(255) NULL,
    entite_id BIGINT UNSIGNED NOT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    derniere_connexion DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_utilisateur_entite
        FOREIGN KEY (entite_id)
        REFERENCES entites(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_utilisateur_entite (entite_id),
    INDEX idx_utilisateur_actif (actif)
) ENGINE=InnoDB;

CREATE TABLE utilisateur_roles (
    utilisateur_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (utilisateur_id, role_id),

    CONSTRAINT fk_ur_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_ur_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 4. INSTRUCTIONS DU GOUVERNEUR
-- ============================================================

CREATE TABLE instructions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    reference VARCHAR(100) NOT NULL UNIQUE,
    objet VARCHAR(500) NOT NULL,
    description TEXT NULL,

    type_instruction_id INT UNSIGNED NOT NULL,
    statut_id INT UNSIGNED NOT NULL,
    priorite_id INT UNSIGNED NOT NULL,

    date_instruction DATE NOT NULL,
    date_reception DATE NULL,
    date_echeance DATE NULL,

    emetteur VARCHAR(255) NULL,

    entite_pilote_id BIGINT UNSIGNED NOT NULL,
    responsable_id BIGINT UNSIGNED NULL,

    taux_avancement DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    date_cloture DATETIME NULL,
    motif_cloture TEXT NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_instruction_type
        FOREIGN KEY (type_instruction_id)
        REFERENCES types_instruction(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_instruction_statut
        FOREIGN KEY (statut_id)
        REFERENCES statuts(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_instruction_priorite
        FOREIGN KEY (priorite_id)
        REFERENCES priorites(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_instruction_entite_pilote
        FOREIGN KEY (entite_pilote_id)
        REFERENCES entites(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_instruction_responsable
        FOREIGN KEY (responsable_id)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_instruction_created_by
        FOREIGN KEY (created_by)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_instruction_type (type_instruction_id),
    INDEX idx_instruction_statut (statut_id),
    INDEX idx_instruction_priorite (priorite_id),
    INDEX idx_instruction_pilote (entite_pilote_id),
    INDEX idx_instruction_responsable (responsable_id),
    INDEX idx_instruction_echeance (date_echeance),
    INDEX idx_instruction_date (date_instruction)
) ENGINE=InnoDB;

-- ============================================================
-- 5. ENTITES CONTRIBUTRICES
-- ============================================================

CREATE TABLE instruction_entites (
    instruction_id BIGINT UNSIGNED NOT NULL,
    entite_id BIGINT UNSIGNED NOT NULL,
    role_entite VARCHAR(50) NOT NULL DEFAULT 'CONTRIBUTEUR',
    commentaire TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (instruction_id, entite_id),

    CONSTRAINT fk_instruction_entite_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_instruction_entite_entite
        FOREIGN KEY (entite_id)
        REFERENCES entites(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_ie_entite (entite_id),
    INDEX idx_ie_role (role_entite)
) ENGINE=InnoDB;

-- ============================================================
-- 6. ACTIONS
-- ============================================================

CREATE TABLE actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    instruction_id BIGINT UNSIGNED NOT NULL,

    reference VARCHAR(100) NULL,
    libelle VARCHAR(500) NOT NULL,
    description TEXT NULL,

    statut_id INT UNSIGNED NOT NULL,
    priorite_id INT UNSIGNED NULL,

    entite_responsable_id BIGINT UNSIGNED NOT NULL,
    responsable_id BIGINT UNSIGNED NULL,

    date_debut DATE NULL,
    date_echeance DATE NULL,
    date_realisation DATE NULL,

    taux_avancement DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    resultat_attendu TEXT NULL,
    resultat_obtenu TEXT NULL,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_action_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_action_statut
        FOREIGN KEY (statut_id)
        REFERENCES statuts(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_action_priorite
        FOREIGN KEY (priorite_id)
        REFERENCES priorites(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_action_entite_responsable
        FOREIGN KEY (entite_responsable_id)
        REFERENCES entites(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_action_responsable
        FOREIGN KEY (responsable_id)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_action_created_by
        FOREIGN KEY (created_by)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_action_instruction (instruction_id),
    INDEX idx_action_statut (statut_id),
    INDEX idx_action_entite (entite_responsable_id),
    INDEX idx_action_responsable (responsable_id),
    INDEX idx_action_echeance (date_echeance)
) ENGINE=InnoDB;

-- ============================================================
-- 7. JUSTIFICATIFS / PIECES
-- ============================================================

CREATE TABLE justificatifs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    instruction_id BIGINT UNSIGNED NULL,
    action_id BIGINT UNSIGNED NULL,

    nom_fichier VARCHAR(255) NOT NULL,
    chemin_fichier VARCHAR(1000) NOT NULL,
    type_mime VARCHAR(150) NULL,
    taille_octets BIGINT UNSIGNED NULL,

    description VARCHAR(500) NULL,

    depose_par BIGINT UNSIGNED NULL,
    date_depot DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_justificatif_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_justificatif_action
        FOREIGN KEY (action_id)
        REFERENCES actions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_justificatif_depose_par
        FOREIGN KEY (depose_par)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_justificatif_instruction (instruction_id),
    INDEX idx_justificatif_action (action_id)
) ENGINE=InnoDB;

-- ============================================================
-- 8. PROROGATIONS
-- ============================================================

CREATE TABLE prorogations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    instruction_id BIGINT UNSIGNED NULL,
    action_id BIGINT UNSIGNED NULL,

    ancienne_echeance DATE NOT NULL,
    nouvelle_echeance DATE NOT NULL,

    motif TEXT NOT NULL,

    demande_par BIGINT UNSIGNED NULL,
    date_demande DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    statut_prorogation_id INT UNSIGNED NOT NULL,

    valide_par BIGINT UNSIGNED NULL,
    date_validation DATETIME NULL,
    commentaire_validation TEXT NULL,

    CONSTRAINT fk_prorogation_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_prorogation_action
        FOREIGN KEY (action_id)
        REFERENCES actions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_prorogation_demandeur
        FOREIGN KEY (demande_par)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_prorogation_statut
        FOREIGN KEY (statut_prorogation_id)
        REFERENCES statuts_prorogation(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_prorogation_validateur
        FOREIGN KEY (valide_par)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_prorogation_instruction (instruction_id),
    INDEX idx_prorogation_action (action_id),
    INDEX idx_prorogation_statut (statut_prorogation_id)
) ENGINE=InnoDB;

-- ============================================================
-- 9. RELANCES
-- ============================================================

CREATE TABLE relances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    instruction_id BIGINT UNSIGNED NULL,
    action_id BIGINT UNSIGNED NULL,

    type_relance_id INT UNSIGNED NOT NULL,
    canal_notification_id INT UNSIGNED NOT NULL,
    statut_relance_id INT UNSIGNED NOT NULL,

    date_planifiee DATETIME NOT NULL,
    date_envoi DATETIME NULL,

    destinataire_utilisateur_id BIGINT UNSIGNED NULL,
    destinataire_email VARCHAR(255) NULL,

    objet VARCHAR(500) NULL,
    message TEXT NULL,

    nombre_tentatives INT NOT NULL DEFAULT 0,
    erreur TEXT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_relance_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_relance_action
        FOREIGN KEY (action_id)
        REFERENCES actions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_relance_type
        FOREIGN KEY (type_relance_id)
        REFERENCES types_relance(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_relance_canal
        FOREIGN KEY (canal_notification_id)
        REFERENCES canaux_notification(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_relance_statut
        FOREIGN KEY (statut_relance_id)
        REFERENCES statuts_relance(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_relance_destinataire
        FOREIGN KEY (destinataire_utilisateur_id)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_relance_instruction (instruction_id),
    INDEX idx_relance_action (action_id),
    INDEX idx_relance_planifiee (date_planifiee),
    INDEX idx_relance_statut (statut_relance_id)
) ENGINE=InnoDB;

-- ============================================================
-- 10. COMMENTAIRES
-- ============================================================

CREATE TABLE commentaires (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    instruction_id BIGINT UNSIGNED NULL,
    action_id BIGINT UNSIGNED NULL,

    utilisateur_id BIGINT UNSIGNED NOT NULL,
    commentaire TEXT NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_commentaire_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_commentaire_action
        FOREIGN KEY (action_id)
        REFERENCES actions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_commentaire_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_commentaire_instruction (instruction_id),
    INDEX idx_commentaire_action (action_id)
) ENGINE=InnoDB;

-- ============================================================
-- 11. HISTORIQUE / PISTE D'AUDIT
-- ============================================================

CREATE TABLE historique (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    instruction_id BIGINT UNSIGNED NULL,
    action_id BIGINT UNSIGNED NULL,

    type_evenement_id INT UNSIGNED NOT NULL,

    utilisateur_id BIGINT UNSIGNED NULL,
    entite_id BIGINT UNSIGNED NULL,

    ancien_statut_id INT UNSIGNED NULL,
    nouveau_statut_id INT UNSIGNED NULL,

    description TEXT NULL,
    donnees_avant JSON NULL,
    donnees_apres JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_historique_instruction
        FOREIGN KEY (instruction_id)
        REFERENCES instructions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_historique_action
        FOREIGN KEY (action_id)
        REFERENCES actions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_historique_evenement
        FOREIGN KEY (type_evenement_id)
        REFERENCES types_evenement(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_historique_utilisateur
        FOREIGN KEY (utilisateur_id)
        REFERENCES utilisateurs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_historique_entite
        FOREIGN KEY (entite_id)
        REFERENCES entites(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_historique_ancien_statut
        FOREIGN KEY (ancien_statut_id)
        REFERENCES statuts(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_historique_nouveau_statut
        FOREIGN KEY (nouveau_statut_id)
        REFERENCES statuts(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_historique_instruction (instruction_id),
    INDEX idx_historique_action (action_id),
    INDEX idx_historique_date (created_at),
    INDEX idx_historique_evenement (type_evenement_id)
) ENGINE=InnoDB;

-- ============================================================
-- 12. DONNEES DE REFERENCE + FIXTURES COMPLETES
-- ============================================================

-- ------------------------------------------------------------
-- 12.1 Types d'instruction
-- ------------------------------------------------------------

INSERT INTO types_instruction
    (code, libelle, description, actif, ordre_affichage)
VALUES
    ('ORDRE_SERVICE', 'Ordre de service',
     'Ordre formel nécessitant une exécution et un suivi.', 1, 1),
    ('INSTRUCTION', 'Instruction',
     'Instruction donnée par le Gouverneur.', 1, 2),
    ('NOTE', 'Note / orientation',
     'Orientation ou demande nécessitant un suivi.', 1, 3),
    ('DECISION', 'Décision',
     'Décision nécessitant des actions de mise en œuvre.', 1, 4),
    ('RECOMMANDATION', 'Recommandation',
     'Recommandation nécessitant un suivi de mise en œuvre.', 1, 5);

-- ------------------------------------------------------------
-- 12.2 Statuts
-- ------------------------------------------------------------

INSERT INTO statuts
    (code, libelle, description, couleur, actif, ordre_affichage)
VALUES
    ('BROUILLON', 'Brouillon',
     'Enregistrement non encore diffusé.', NULL, 1, 1),
    ('AFFECTEE', 'Affectée',
     'Instruction affectée à une entité responsable.', NULL, 1, 2),
    ('EN_COURS', 'En cours',
     'Mise en œuvre en cours.', NULL, 1, 3),
    ('EN_ATTENTE', 'En attente',
     'Exécution temporairement suspendue ou dépendante d’un élément.', NULL, 1, 4),
    ('A_VERIFIER', 'À vérifier',
     'Exécution annoncée, en attente de contrôle.', NULL, 1, 5),
    ('EXECUTEE', 'Exécutée',
     'Instruction ou action exécutée.', NULL, 1, 6),
    ('CLOTUREE', 'Clôturée',
     'Instruction ou action vérifiée et officiellement clôturée.', NULL, 1, 7),
    ('ANNULEE', 'Annulée',
     'Instruction ou action annulée.', NULL, 1, 8);

-- ------------------------------------------------------------
-- 12.3 Priorités
-- ------------------------------------------------------------

INSERT INTO priorites
    (code, libelle, niveau, actif, ordre_affichage)
VALUES
    ('FAIBLE', 'Faible', 1, 1, 1),
    ('NORMALE', 'Normale', 2, 1, 2),
    ('HAUTE', 'Haute', 3, 1, 3),
    ('URGENTE', 'Urgente', 4, 1, 4);

-- ------------------------------------------------------------
-- 12.4 Types de relance
-- ------------------------------------------------------------

INSERT INTO types_relance
    (code, libelle, description, actif, ordre_affichage)
VALUES
    ('J_7', 'J-7', 'Relance sept jours avant échéance.', 1, 1),
    ('J_3', 'J-3', 'Relance trois jours avant échéance.', 1, 2),
    ('J_1', 'J-1', 'Relance un jour avant échéance.', 1, 3),
    ('J0', 'Jour J', 'Relance le jour de l’échéance.', 1, 4),
    ('J_PLUS_1', 'J+1', 'Relance un jour après échéance.', 1, 5),
    ('J_PLUS_7', 'J+7', 'Relance sept jours après échéance.', 1, 6),
    ('MANUELLE', 'Manuelle', 'Relance déclenchée manuellement.', 1, 7);

-- ------------------------------------------------------------
-- 12.5 Statuts de relance
-- ------------------------------------------------------------

INSERT INTO statuts_relance
    (code, libelle, description, actif, ordre_affichage)
VALUES
    ('PLANIFIEE', 'Planifiée',
     'Relance programmée mais non encore envoyée.', 1, 1),
    ('ENVOYEE', 'Envoyée',
     'Relance envoyée avec succès.', 1, 2),
    ('ECHEC', 'Échec',
     'Tentative d’envoi échouée.', 1, 3),
    ('ANNULEE', 'Annulée',
     'Relance annulée.', 1, 4);

-- ------------------------------------------------------------
-- 12.6 Canaux de notification
-- ------------------------------------------------------------

INSERT INTO canaux_notification
    (code, libelle, actif, ordre_affichage)
VALUES
    ('EMAIL', 'E-mail', 1, 1),
    ('APPLICATION', 'Notification dans l’application', 1, 2),
    ('SMS', 'SMS', 1, 3);

-- ------------------------------------------------------------
-- 12.7 Statuts de prorogation
-- ------------------------------------------------------------

INSERT INTO statuts_prorogation
    (code, libelle, description, actif, ordre_affichage)
VALUES
    ('EN_ATTENTE', 'En attente',
     'Demande soumise mais non encore traitée.', 1, 1),
    ('APPROUVEE', 'Approuvée',
     'Nouvelle échéance approuvée.', 1, 2),
    ('REJETEE', 'Rejetée',
     'Demande de prorogation rejetée.', 1, 3),
    ('ANNULEE', 'Annulée',
     'Demande annulée.', 1, 4);

-- ------------------------------------------------------------
-- 12.8 Types d'événement
-- ------------------------------------------------------------

INSERT INTO types_evenement
    (code, libelle, description, actif, ordre_affichage)
VALUES
    ('CREATION', 'Création',
     'Création d’un objet de suivi.', 1, 1),
    ('MODIFICATION', 'Modification',
     'Modification des données.', 1, 2),
    ('AFFECTATION', 'Affectation',
     'Affectation à une entité ou un responsable.', 1, 3),
    ('CHANGEMENT_STATUT', 'Changement de statut',
     'Évolution du statut.', 1, 4),
    ('COMMENTAIRE', 'Commentaire',
     'Ajout d’un commentaire.', 1, 5),
    ('JUSTIFICATIF_AJOUTE', 'Justificatif ajouté',
     'Ajout d’une pièce justificative.', 1, 6),
    ('PROROGATION_DEMANDEE', 'Prorogation demandée',
     'Demande de prolongation.', 1, 7),
    ('PROROGATION_VALIDEE', 'Prorogation validée',
     'Validation d’une prolongation.', 1, 8),
    ('RELANCE', 'Relance',
     'Relance envoyée ou planifiée.', 1, 9),
    ('CLOTURE', 'Clôture',
     'Clôture de l’instruction ou de l’action.', 1, 10);

-- ------------------------------------------------------------
-- 12.9 Types d'entité
-- ------------------------------------------------------------

INSERT INTO types_entite
    (code, libelle, niveau, description, actif, ordre_affichage)
VALUES
    ('DIRECTION_GENERALE', 'Direction Générale', 1,
     'Entité de niveau Direction Générale.', 1, 1),
    ('DIRECTION', 'Direction', 2,
     'Direction rattachée à une Direction Générale.', 1, 2),
    ('SERVICE', 'Service', 3,
     'Service rattaché à une Direction.', 1, 3),
    ('BUREAU', 'Bureau', 4,
     'Bureau rattaché à un Service.', 1, 4),
    ('CELLULE', 'Cellule', 4,
     'Cellule rattachée à une Direction ou un Service.', 1, 5),
    ('SECRETARIAT', 'Secrétariat', 4,
     'Secrétariat rattaché à une entité.', 1, 6);

-- ------------------------------------------------------------
-- 12.10 Rôles
-- ------------------------------------------------------------

INSERT INTO roles
    (code, libelle, description, actif)
VALUES
    ('ADMIN', 'Administrateur',
     'Administration technique et paramétrage.', 1),
    ('CABINET', 'Cabinet / Secrétariat',
     'Enregistrement, diffusion et supervision des instructions.', 1),
    ('DG', 'Responsable Direction Générale',
     'Supervision des directions rattachées.', 1),
    ('DIRECTEUR', 'Directeur',
     'Pilotage des instructions et actions de son entité.', 1),
    ('RESPONSABLE', 'Responsable',
     'Exécution et mise à jour des actions.', 1),
    ('CONSULTATION', 'Consultation',
     'Accès en lecture aux données autorisées.', 1);

-- ============================================================
-- 12.11 FIXTURES ORGANISATIONNELLES
-- ============================================================
-- Les noms sont des données de démonstration.
-- Ils peuvent être remplacés par la nomenclature officielle BCC.

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'DG_OP',
    'Direction Générale des Opérations',
    id,
    NULL,
    'Direction Générale de démonstration.', 1
FROM types_entite WHERE code = 'DIRECTION_GENERALE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'DG_SI',
    'Direction Générale des Systèmes et de la Transformation',
    id,
    NULL,
    'Direction Générale de démonstration.', 1
FROM types_entite WHERE code = 'DIRECTION_GENERALE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'DG_ADMIN',
    'Direction Générale Administrative',
    id,
    NULL,
    'Direction Générale de démonstration.', 1
FROM types_entite WHERE code = 'DIRECTION_GENERALE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'DOPM',
    'Direction des Opérations Bancaires et des Marchés',
    id,
    (SELECT id FROM entites WHERE code = 'DG_OP'),
    'Direction de démonstration.', 1
FROM types_entite WHERE code = 'DIRECTION';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'DRH',
    'Direction des Ressources Humaines',
    id,
    (SELECT id FROM entites WHERE code = 'DG_ADMIN'),
    'Direction de démonstration.', 1
FROM types_entite WHERE code = 'DIRECTION';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'DSI',
    'Direction des Systèmes d’Information',
    id,
    (SELECT id FROM entites WHERE code = 'DG_SI'),
    'Direction de démonstration.', 1
FROM types_entite WHERE code = 'DIRECTION';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'SM',
    'Service des Marchés',
    id,
    (SELECT id FROM entites WHERE code = 'DOPM'),
    'Service de démonstration.', 1
FROM types_entite WHERE code = 'SERVICE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'SOB',
    'Service des Opérations Bancaires',
    id,
    (SELECT id FROM entites WHERE code = 'DOPM'),
    'Service de démonstration.', 1
FROM types_entite WHERE code = 'SERVICE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'SINFRA',
    'Service Infrastructure',
    id,
    (SELECT id FROM entites WHERE code = 'DSI'),
    'Service de démonstration.', 1
FROM types_entite WHERE code = 'SERVICE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'SAPPLI',
    'Service Applications',
    id,
    (SELECT id FROM entites WHERE code = 'DSI'),
    'Service de démonstration.', 1
FROM types_entite WHERE code = 'SERVICE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'B_MARCHES',
    'Bureau Suivi des Marchés',
    id,
    (SELECT id FROM entites WHERE code = 'SM'),
    'Bureau de démonstration.', 1
FROM types_entite WHERE code = 'BUREAU';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'B_CORE',
    'Bureau Applications Core Banking',
    id,
    (SELECT id FROM entites WHERE code = 'SAPPLI'),
    'Bureau de démonstration.', 1
FROM types_entite WHERE code = 'BUREAU';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'C_SECURITE',
    'Cellule Sécurité des Systèmes',
    id,
    (SELECT id FROM entites WHERE code = 'DSI'),
    'Cellule de démonstration.', 1
FROM types_entite WHERE code = 'CELLULE';

INSERT INTO entites
    (code, nom, type_entite_id, parent_id, description, actif)
SELECT
    'SEC_DG_OP',
    'Secrétariat de la Direction Générale des Opérations',
    id,
    (SELECT id FROM entites WHERE code = 'DG_OP'),
    'Secrétariat de démonstration.', 1
FROM types_entite WHERE code = 'SECRETARIAT';

-- ============================================================
-- 12.12 FIXTURES UTILISATEURS
-- ============================================================

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS001', 'Admin', NULL, 'Système',
    'admin.actis@bcc.local', '+243000000001',
    '$2y$10$fixture_hash_admin',
    e.id, 1
FROM entites e WHERE e.code = 'DSI';

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS002', 'Kabeya', 'Mwamba', 'Paul',
    'paul.kabeya@bcc.local', '+243000000002',
    '$2y$10$fixture_hash_cabinet',
    e.id, 1
FROM entites e WHERE e.code = 'SEC_DG_OP';

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS003', 'Mukendi', 'Ilunga', 'Marie',
    'marie.mukendi@bcc.local', '+243000000003',
    '$2y$10$fixture_hash_dg',
    e.id, 1
FROM entites e WHERE e.code = 'DG_OP';

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS004', 'Tshibanda', 'Kanku', 'Jean',
    'jean.tshibanda@bcc.local', '+243000000004',
    '$2y$10$fixture_hash_directeur',
    e.id, 1
FROM entites e WHERE e.code = 'DOPM';

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS005', 'Ilunga', 'Kabongo', 'Patrick',
    'patrick.ilunga@bcc.local', '+243000000005',
    '$2y$10$fixture_hash_responsable',
    e.id, 1
FROM entites e WHERE e.code = 'SM';

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS006', 'Mbuyi', 'Kalala', 'Nathalie',
    'nathalie.mbuyi@bcc.local', '+243000000006',
    '$2y$10$fixture_hash_responsable2',
    e.id, 1
FROM entites e WHERE e.code = 'SAPPLI';

INSERT INTO utilisateurs
    (matricule, nom, postnom, prenom, email, telephone,
     mot_de_passe_hash, entite_id, actif)
SELECT
    'ACTIS007', 'Kasongo', 'Lukusa', 'David',
    'david.kasongo@bcc.local', '+243000000007',
    '$2y$10$fixture_hash_consultation',
    e.id, 1
FROM entites e WHERE e.code = 'DRH';

-- ============================================================
-- 12.13 ASSOCIATION UTILISATEURS / ROLES
-- ============================================================

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS001' AND r.code = 'ADMIN';

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS002' AND r.code = 'CABINET';

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS003' AND r.code = 'DG';

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS004' AND r.code = 'DIRECTEUR';

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS005' AND r.code = 'RESPONSABLE';

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS006' AND r.code = 'RESPONSABLE';

INSERT INTO utilisateur_roles (utilisateur_id, role_id)
SELECT u.id, r.id
FROM utilisateurs u
CROSS JOIN roles r
WHERE u.matricule = 'ACTIS007' AND r.code = 'CONSULTATION';

-- ============================================================
-- 12.14 FIXTURES INSTRUCTIONS
-- ============================================================

INSERT INTO instructions
    (reference, objet, description,
     type_instruction_id, statut_id, priorite_id,
     date_instruction, date_reception, date_echeance,
     emetteur, entite_pilote_id, responsable_id,
     taux_avancement, date_cloture, motif_cloture, created_by)
SELECT
    'OSG-2026-001',
    'Renforcement du dispositif de suivi des opérations bancaires',
    'Instruction de démonstration visant à renforcer le suivi et la remontée des informations opérationnelles.',
    ti.id, s.id, p.id,
    '2026-08-01', '2026-08-01', '2026-09-30',
    'Gouverneur',
    e.id, u.id,
    65.00, NULL, NULL, c.id
FROM types_instruction ti
CROSS JOIN statuts s
CROSS JOIN priorites p
JOIN entites e ON e.code = 'DOPM'
JOIN utilisateurs u ON u.matricule = 'ACTIS004'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE ti.code = 'ORDRE_SERVICE'
  AND s.code = 'EN_COURS'
  AND p.code = 'HAUTE';

INSERT INTO instructions
    (reference, objet, description,
     type_instruction_id, statut_id, priorite_id,
     date_instruction, date_reception, date_echeance,
     emetteur, entite_pilote_id, responsable_id,
     taux_avancement, date_cloture, motif_cloture, created_by)
SELECT
    'ING-2026-002',
    'Mise en place d’un dispositif de suivi des instructions du Gouverneur',
    'Instruction de démonstration correspondant au projet ACTIS.',
    ti.id, s.id, p.id,
    '2026-08-15', '2026-08-15', '2026-10-15',
    'Gouverneur',
    e.id, u.id,
    40.00, NULL, NULL, c.id
FROM types_instruction ti
CROSS JOIN statuts s
CROSS JOIN priorites p
JOIN entites e ON e.code = 'DSI'
JOIN utilisateurs u ON u.matricule = 'ACTIS006'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE ti.code = 'INSTRUCTION'
  AND s.code = 'EN_COURS'
  AND p.code = 'URGENTE';

INSERT INTO instructions
    (reference, objet, description,
     type_instruction_id, statut_id, priorite_id,
     date_instruction, date_reception, date_echeance,
     emetteur, entite_pilote_id, responsable_id,
     taux_avancement, date_cloture, motif_cloture, created_by)
SELECT
    'DEC-2026-003',
    'Mise en œuvre d’un plan de renforcement des compétences',
    'Décision de démonstration clôturée.',
    ti.id, s.id, p.id,
    '2026-06-10', '2026-06-10', '2026-08-31',
    'Gouverneur',
    e.id, u.id,
    100.00, '2026-08-28 15:30:00',
    'Actions réalisées et vérifiées.',
    c.id
FROM types_instruction ti
CROSS JOIN statuts s
CROSS JOIN priorites p
JOIN entites e ON e.code = 'DRH'
JOIN utilisateurs u ON u.matricule = 'ACTIS007'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE ti.code = 'DECISION'
  AND s.code = 'CLOTUREE'
  AND p.code = 'NORMALE';

-- ============================================================
-- 12.15 ENTITES CONTRIBUTRICES
-- ============================================================

INSERT INTO instruction_entites
    (instruction_id, entite_id, role_entite, commentaire)
SELECT
    i.id, e.id, 'CONTRIBUTEUR',
    'Contribution du Service des Opérations Bancaires.'
FROM instructions i
JOIN entites e ON e.code = 'SOB'
WHERE i.reference = 'OSG-2026-001';

INSERT INTO instruction_entites
    (instruction_id, entite_id, role_entite, commentaire)
SELECT
    i.id, e.id, 'CONTRIBUTEUR',
    'Contribution du Service Infrastructure.'
FROM instructions i
JOIN entites e ON e.code = 'SINFRA'
WHERE i.reference = 'ING-2026-002';

INSERT INTO instruction_entites
    (instruction_id, entite_id, role_entite, commentaire)
SELECT
    i.id, e.id, 'CONSULTEE',
    'Entité consultée pour avis.'
FROM instructions i
JOIN entites e ON e.code = 'DRH'
WHERE i.reference = 'ING-2026-002';

-- ============================================================
-- 12.16 FIXTURES ACTIONS
-- ============================================================

INSERT INTO actions
    (instruction_id, reference, libelle, description,
     statut_id, priorite_id, entite_responsable_id, responsable_id,
     date_debut, date_echeance, date_realisation,
     taux_avancement, resultat_attendu, resultat_obtenu, created_by)
SELECT
    i.id,
    'OSG-2026-001-A01',
    'Établir la situation consolidée des opérations',
    'Préparer une situation consolidée et documentée.',
    s.id, p.id, e.id, u.id,
    '2026-08-03', '2026-09-15', NULL,
    80.00,
    'Situation consolidée validée.',
    NULL,
    c.id
FROM instructions i
JOIN statuts s ON s.code = 'EN_COURS'
JOIN priorites p ON p.code = 'HAUTE'
JOIN entites e ON e.code = 'SM'
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE i.reference = 'OSG-2026-001';

INSERT INTO actions
    (instruction_id, reference, libelle, description,
     statut_id, priorite_id, entite_responsable_id, responsable_id,
     date_debut, date_echeance, date_realisation,
     taux_avancement, resultat_attendu, resultat_obtenu, created_by)
SELECT
    i.id,
    'OSG-2026-001-A02',
    'Mettre en place un mécanisme de remontée périodique',
    'Définir le format et la périodicité des remontées.',
    s.id, p.id, e.id, u.id,
    '2026-08-05', '2026-09-05', NULL,
    55.00,
    'Mécanisme opérationnel et documenté.',
    NULL,
    c.id
FROM instructions i
JOIN statuts s ON s.code = 'EN_COURS'
JOIN priorites p ON p.code = 'NORMALE'
JOIN entites e ON e.code = 'SOB'
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE i.reference = 'OSG-2026-001';

INSERT INTO actions
    (instruction_id, reference, libelle, description,
     statut_id, priorite_id, entite_responsable_id, responsable_id,
     date_debut, date_echeance, date_realisation,
     taux_avancement, resultat_attendu, resultat_obtenu, created_by)
SELECT
    i.id,
    'ING-2026-002-A01',
    'Concevoir le registre central des instructions',
    'Définir les données, workflow et règles de suivi.',
    s.id, p.id, e.id, u.id,
    '2026-08-18', '2026-09-20', NULL,
    60.00,
    'Registre central fonctionnel.',
    NULL,
    c.id
FROM instructions i
JOIN statuts s ON s.code = 'EN_COURS'
JOIN priorites p ON p.code = 'URGENTE'
JOIN entites e ON e.code = 'SAPPLI'
JOIN utilisateurs u ON u.matricule = 'ACTIS006'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE i.reference = 'ING-2026-002';

INSERT INTO actions
    (instruction_id, reference, libelle, description,
     statut_id, priorite_id, entite_responsable_id, responsable_id,
     date_debut, date_echeance, date_realisation,
     taux_avancement, resultat_attendu, resultat_obtenu, created_by)
SELECT
    i.id,
    'ING-2026-002-A02',
    'Préparer le dispositif de notification et de relance',
    'Définir les relances automatiques et les canaux de notification.',
    s.id, p.id, e.id, u.id,
    '2026-08-25', '2026-09-25', NULL,
    25.00,
    'Mécanisme de notification configuré.',
    NULL,
    c.id
FROM instructions i
JOIN statuts s ON s.code = 'EN_COURS'
JOIN priorites p ON p.code = 'HAUTE'
JOIN entites e ON e.code = 'C_SECURITE'
JOIN utilisateurs u ON u.matricule = 'ACTIS006'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE i.reference = 'ING-2026-002';

INSERT INTO actions
    (instruction_id, reference, libelle, description,
     statut_id, priorite_id, entite_responsable_id, responsable_id,
     date_debut, date_echeance, date_realisation,
     taux_avancement, resultat_attendu, resultat_obtenu, created_by)
SELECT
    i.id,
    'DEC-2026-003-A01',
    'Organiser les sessions de renforcement des compétences',
    'Action de démonstration terminée.',
    s.id, p.id, e.id, u.id,
    '2026-06-15', '2026-08-20', '2026-08-18',
    100.00,
    'Sessions réalisées.',
    'Sessions réalisées et évaluées.',
    c.id
FROM instructions i
JOIN statuts s ON s.code = 'EXECUTEE'
JOIN priorites p ON p.code = 'NORMALE'
JOIN entites e ON e.code = 'DRH'
JOIN utilisateurs u ON u.matricule = 'ACTIS007'
JOIN utilisateurs c ON c.matricule = 'ACTIS002'
WHERE i.reference = 'DEC-2026-003';

-- ============================================================
-- 12.17 JUSTIFICATIFS
-- ============================================================

INSERT INTO justificatifs
    (instruction_id, action_id, nom_fichier, chemin_fichier,
     type_mime, taille_octets, description, depose_par)
SELECT
    i.id, NULL,
    'note_cadrage_actis.pdf',
    '/documents/actis/2026/note_cadrage_actis.pdf',
    'application/pdf', 245760,
    'Note de cadrage du projet ACTIS.',
    u.id
FROM instructions i
JOIN utilisateurs u ON u.matricule = 'ACTIS002'
WHERE i.reference = 'ING-2026-002';

INSERT INTO justificatifs
    (instruction_id, action_id, nom_fichier, chemin_fichier,
     type_mime, taille_octets, description, depose_par)
SELECT
    NULL, a.id,
    'situation_operations_septembre.xlsx',
    '/documents/actis/2026/situations/situation_operations_septembre.xlsx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    532480,
    'Situation consolidée des opérations.',
    u.id
FROM actions a
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
WHERE a.reference = 'OSG-2026-001-A01';

INSERT INTO justificatifs
    (instruction_id, action_id, nom_fichier, chemin_fichier,
     type_mime, taille_octets, description, depose_par)
SELECT
    NULL, a.id,
    'rapport_formation_2026.pdf',
    '/documents/actis/2026/drh/rapport_formation_2026.pdf',
    'application/pdf', 389120,
    'Rapport de réalisation des sessions de formation.',
    u.id
FROM actions a
JOIN utilisateurs u ON u.matricule = 'ACTIS007'
WHERE a.reference = 'DEC-2026-003-A01';

-- ============================================================
-- 12.18 PROROGATIONS
-- ============================================================

INSERT INTO prorogations
    (instruction_id, action_id,
     ancienne_echeance, nouvelle_echeance, motif,
     demande_par, date_demande, statut_prorogation_id,
     valide_par, date_validation, commentaire_validation)
SELECT
    NULL, a.id,
    '2026-09-05', '2026-09-12',
    'Dépendance à la consolidation des données provenant de plusieurs unités.',
    u.id, '2026-09-04 10:30:00',
    sp.id,
    v.id, '2026-09-05 14:00:00',
    'Prorogation approuvée pour finaliser la consolidation.'
FROM actions a
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
JOIN utilisateurs v ON v.matricule = 'ACTIS004'
JOIN statuts_prorogation sp ON sp.code = 'APPROUVEE'
WHERE a.reference = 'OSG-2026-001-A02';

INSERT INTO prorogations
    (instruction_id, action_id,
     ancienne_echeance, nouvelle_echeance, motif,
     demande_par, date_demande, statut_prorogation_id,
     valide_par, date_validation, commentaire_validation)
SELECT
    i.id, NULL,
    '2026-09-30', '2026-10-15',
    'Temps supplémentaire nécessaire pour finaliser la validation institutionnelle.',
    u.id, '2026-09-01 09:00:00',
    sp.id,
    NULL, NULL, NULL
FROM instructions i
JOIN utilisateurs u ON u.matricule = 'ACTIS004'
JOIN statuts_prorogation sp ON sp.code = 'EN_ATTENTE'
WHERE i.reference = 'OSG-2026-001';

-- ============================================================
-- 12.19 RELANCES
-- ============================================================

INSERT INTO relances
    (instruction_id, action_id,
     type_relance_id, canal_notification_id, statut_relance_id,
     date_planifiee, date_envoi,
     destinataire_utilisateur_id, destinataire_email,
     objet, message, nombre_tentatives, erreur)
SELECT
    NULL, a.id,
    tr.id, cn.id, sr.id,
    '2026-09-08 08:00:00', '2026-09-08 08:01:15',
    u.id, u.email,
    'Rappel – échéance de l’action OSG-2026-001-A02',
    'Votre action arrive à échéance. Merci de mettre à jour son état dans ACTIS.',
    1, NULL
FROM actions a
JOIN types_relance tr ON tr.code = 'J_1'
JOIN canaux_notification cn ON cn.code = 'EMAIL'
JOIN statuts_relance sr ON sr.code = 'ENVOYEE'
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
WHERE a.reference = 'OSG-2026-001-A02';

INSERT INTO relances
    (instruction_id, action_id,
     type_relance_id, canal_notification_id, statut_relance_id,
     date_planifiee, date_envoi,
     destinataire_utilisateur_id, destinataire_email,
     objet, message, nombre_tentatives, erreur)
SELECT
    NULL, a.id,
    tr.id, cn.id, sr.id,
    '2026-09-25 08:00:00', NULL,
    u.id, u.email,
    'Rappel – échéance de l’action ING-2026-002-A02',
    'Relance automatique planifiée avant échéance.',
    0, NULL
FROM actions a
JOIN types_relance tr ON tr.code = 'J_1'
JOIN canaux_notification cn ON cn.code = 'APPLICATION'
JOIN statuts_relance sr ON sr.code = 'PLANIFIEE'
JOIN utilisateurs u ON u.matricule = 'ACTIS006'
WHERE a.reference = 'ING-2026-002-A02';

INSERT INTO relances
    (instruction_id, action_id,
     type_relance_id, canal_notification_id, statut_relance_id,
     date_planifiee, date_envoi,
     destinataire_utilisateur_id, destinataire_email,
     objet, message, nombre_tentatives, erreur)
SELECT
    i.id, NULL,
    tr.id, cn.id, sr.id,
    '2026-09-23 08:00:00', NULL,
    u.id, u.email,
    'Rappel – échéance de l’instruction OSG-2026-001',
    'Relance planifiée pour le suivi de l’instruction.',
    0, NULL
FROM instructions i
JOIN types_relance tr ON tr.code = 'J_7'
JOIN canaux_notification cn ON cn.code = 'EMAIL'
JOIN statuts_relance sr ON sr.code = 'PLANIFIEE'
JOIN utilisateurs u ON u.matricule = 'ACTIS004'
WHERE i.reference = 'OSG-2026-001';

-- ============================================================
-- 12.20 COMMENTAIRES
-- ============================================================

INSERT INTO commentaires
    (instruction_id, action_id, utilisateur_id, commentaire)
SELECT
    i.id, NULL, u.id,
    'Instruction enregistrée et affectée à la Direction pilote.'
FROM instructions i
JOIN utilisateurs u ON u.matricule = 'ACTIS002'
WHERE i.reference = 'OSG-2026-001';

INSERT INTO commentaires
    (instruction_id, action_id, utilisateur_id, commentaire)
SELECT
    NULL, a.id, u.id,
    'La consolidation des données est en cours. Une partie des informations a déjà été reçue.'
FROM actions a
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
WHERE a.reference = 'OSG-2026-001-A01';

INSERT INTO commentaires
    (instruction_id, action_id, utilisateur_id, commentaire)
SELECT
    i.id, NULL, u.id,
    'Le développement du registre central a démarré. Le modèle de données est en cours de validation.'
FROM instructions i
JOIN utilisateurs u ON u.matricule = 'ACTIS006'
WHERE i.reference = 'ING-2026-002';

INSERT INTO commentaires
    (instruction_id, action_id, utilisateur_id, commentaire)
SELECT
    NULL, a.id, u.id,
    'Action réalisée. Les justificatifs ont été déposés.'
FROM actions a
JOIN utilisateurs u ON u.matricule = 'ACTIS007'
WHERE a.reference = 'DEC-2026-003-A01';

-- ============================================================
-- 12.21 HISTORIQUE / PISTE D'AUDIT
-- ============================================================

INSERT INTO historique
    (instruction_id, action_id, type_evenement_id,
     utilisateur_id, entite_id,
     ancien_statut_id, nouveau_statut_id,
     description, donnees_avant, donnees_apres)
SELECT
    i.id, NULL, te.id,
    u.id, e.id,
    NULL, s.id,
    'Création de l’instruction dans ACTIS.',
    NULL,
    JSON_OBJECT(
        'reference', i.reference,
        'objet', i.objet,
        'statut', s.code
    )
FROM instructions i
JOIN types_evenement te ON te.code = 'CREATION'
JOIN utilisateurs u ON u.matricule = 'ACTIS002'
JOIN entites e ON e.code = 'DOPM'
JOIN statuts s ON s.id = i.statut_id
WHERE i.reference = 'OSG-2026-001';

INSERT INTO historique
    (instruction_id, action_id, type_evenement_id,
     utilisateur_id, entite_id,
     ancien_statut_id, nouveau_statut_id,
     description, donnees_avant, donnees_apres)
SELECT
    i.id, NULL, te.id,
    u.id, e.id,
    s_old.id, s_new.id,
    'Passage de l’instruction au statut En cours.',
    JSON_OBJECT('statut', s_old.code),
    JSON_OBJECT('statut', s_new.code)
FROM instructions i
JOIN types_evenement te ON te.code = 'CHANGEMENT_STATUT'
JOIN utilisateurs u ON u.matricule = 'ACTIS004'
JOIN entites e ON e.code = 'DOPM'
JOIN statuts s_old ON s_old.code = 'AFFECTEE'
JOIN statuts s_new ON s_new.code = 'EN_COURS'
WHERE i.reference = 'OSG-2026-001';

INSERT INTO historique
    (instruction_id, action_id, type_evenement_id,
     utilisateur_id, entite_id,
     ancien_statut_id, nouveau_statut_id,
     description, donnees_avant, donnees_apres)
SELECT
    NULL, a.id, te.id,
    u.id, e.id,
    NULL, s.id,
    'Création de l’action rattachée à l’instruction.',
    NULL,
    JSON_OBJECT(
        'reference', a.reference,
        'libelle', a.libelle,
        'avancement', a.taux_avancement
    )
FROM actions a
JOIN types_evenement te ON te.code = 'CREATION'
JOIN utilisateurs u ON u.matricule = 'ACTIS005'
JOIN entites e ON e.code = 'SM'
JOIN statuts s ON s.id = a.statut_id
WHERE a.reference = 'OSG-2026-001-A01';

INSERT INTO historique
    (instruction_id, action_id, type_evenement_id,
     utilisateur_id, entite_id,
     ancien_statut_id, nouveau_statut_id,
     description, donnees_avant, donnees_apres)
SELECT
    NULL, a.id, te.id,
    u.id, e.id,
    s_old.id, s_new.id,
    'Action exécutée et soumise à clôture.',
    JSON_OBJECT('statut', s_old.code),
    JSON_OBJECT('statut', s_new.code)
FROM actions a
JOIN types_evenement te ON te.code = 'CHANGEMENT_STATUT'
JOIN utilisateurs u ON u.matricule = 'ACTIS007'
JOIN entites e ON e.code = 'DRH'
JOIN statuts s_old ON s_old.code = 'EN_COURS'
JOIN statuts s_new ON s_new.code = 'EXECUTEE'
WHERE a.reference = 'DEC-2026-003-A01';

INSERT INTO historique
    (instruction_id, action_id, type_evenement_id,
     utilisateur_id, entite_id,
     ancien_statut_id, nouveau_statut_id,
     description, donnees_avant, donnees_apres)
SELECT
    i.id, NULL, te.id,
    u.id, e.id,
    s_old.id, s_new.id,
    'Instruction clôturée après vérification.',
    JSON_OBJECT('statut', s_old.code),
    JSON_OBJECT('statut', s_new.code)
FROM instructions i
JOIN types_evenement te ON te.code = 'CLOTURE'
JOIN utilisateurs u ON u.matricule = 'ACTIS002'
JOIN entites e ON e.code = 'DRH'
JOIN statuts s_old ON s_old.code = 'EXECUTEE'
JOIN statuts s_new ON s_new.code = 'CLOTUREE'
WHERE i.reference = 'DEC-2026-003';

-- ============================================================
-- 13. REQUETES DE CONTROLE DES FIXTURES
-- ============================================================
-- Ces requêtes sont commentées volontairement.
--
-- SELECT COUNT(*) AS nb_types_instruction FROM types_instruction;
-- SELECT COUNT(*) AS nb_statuts FROM statuts;
-- SELECT COUNT(*) AS nb_priorites FROM priorites;
-- SELECT COUNT(*) AS nb_types_relance FROM types_relance;
-- SELECT COUNT(*) AS nb_statuts_relance FROM statuts_relance;
-- SELECT COUNT(*) AS nb_canaux FROM canaux_notification;
-- SELECT COUNT(*) AS nb_statuts_prorogation FROM statuts_prorogation;
-- SELECT COUNT(*) AS nb_types_evenement FROM types_evenement;
-- SELECT COUNT(*) AS nb_types_entite FROM types_entite;
-- SELECT COUNT(*) AS nb_entites FROM entites;
-- SELECT COUNT(*) AS nb_roles FROM roles;
-- SELECT COUNT(*) AS nb_utilisateurs FROM utilisateurs;
-- SELECT COUNT(*) AS nb_utilisateur_roles FROM utilisateur_roles;
-- SELECT COUNT(*) AS nb_instructions FROM instructions;
-- SELECT COUNT(*) AS nb_instruction_entites FROM instruction_entites;
-- SELECT COUNT(*) AS nb_actions FROM actions;
-- SELECT COUNT(*) AS nb_justificatifs FROM justificatifs;
-- SELECT COUNT(*) AS nb_prorogations FROM prorogations;
-- SELECT COUNT(*) AS nb_relances FROM relances;
-- SELECT COUNT(*) AS nb_commentaires FROM commentaires;
-- SELECT COUNT(*) AS nb_historique FROM historique;

-- ============================================================
-- ============================================================
-- 14. VUES DE PILOTAGE
-- ============================================================

CREATE VIEW v_actions_en_retard AS
SELECT
    a.id AS action_id,
    a.instruction_id,
    i.reference AS instruction_reference,
    i.objet AS instruction_objet,

    a.libelle AS action,
    a.date_echeance,

    DATEDIFF(CURDATE(), a.date_echeance) AS jours_retard,

    s.code AS statut_code,
    s.libelle AS statut,

    e.id AS entite_responsable_id,
    e.code AS entite_responsable_code,
    e.nom AS entite_responsable,

    CONCAT_WS(' ', u.prenom, u.nom) AS responsable
FROM actions a
INNER JOIN instructions i
    ON i.id = a.instruction_id
INNER JOIN statuts s
    ON s.id = a.statut_id
INNER JOIN entites e
    ON e.id = a.entite_responsable_id
LEFT JOIN utilisateurs u
    ON u.id = a.responsable_id
WHERE
    a.date_echeance IS NOT NULL
    AND a.date_echeance < CURDATE()
    AND s.code NOT IN ('EXECUTEE', 'CLOTUREE', 'ANNULEE');

CREATE VIEW v_dashboard_instructions AS
SELECT
    i.id,
    i.reference,
    i.objet,

    ti.libelle AS type_instruction,
    s.code AS statut_code,
    s.libelle AS statut,
    p.libelle AS priorite,

    i.date_instruction,
    i.date_echeance,

    e.id AS entite_pilote_id,
    e.code AS entite_pilote_code,
    e.nom AS entite_pilote,

    te.libelle AS type_entite_pilote,

    CONCAT_WS(' ', u.prenom, u.nom) AS responsable,

    i.taux_avancement,

    CASE
        WHEN s.code IN ('EXECUTEE', 'CLOTUREE', 'ANNULEE') THEN 'NON'
        WHEN i.date_echeance IS NOT NULL
             AND i.date_echeance < CURDATE() THEN 'OUI'
        ELSE 'NON'
    END AS en_retard,

    CASE
        WHEN i.date_echeance IS NULL THEN NULL
        ELSE DATEDIFF(i.date_echeance, CURDATE())
    END AS jours_avant_echeance

FROM instructions i
INNER JOIN types_instruction ti
    ON ti.id = i.type_instruction_id
INNER JOIN statuts s
    ON s.id = i.statut_id
INNER JOIN priorites p
    ON p.id = i.priorite_id
INNER JOIN entites e
    ON e.id = i.entite_pilote_id
INNER JOIN types_entite te
    ON te.id = e.type_entite_id
LEFT JOIN utilisateurs u
    ON u.id = i.responsable_id;

-- ============================================================
-- Performance des entités de niveau Direction
-- Les actions sont agrégées au niveau de leur entité responsable.
-- ============================================================

CREATE VIEW v_performance_directions AS
SELECT
    parent.id AS direction_generale_id,
    parent.code AS direction_generale_code,
    parent.nom AS direction_generale,

    e.id AS direction_id,
    e.code AS direction_code,
    e.nom AS direction,

    COUNT(DISTINCT a.id) AS total_actions,

    SUM(
        CASE
            WHEN s.code IN ('EXECUTEE', 'CLOTUREE') THEN 1
            ELSE 0
        END
    ) AS actions_terminees,

    SUM(
        CASE
            WHEN a.date_echeance IS NOT NULL
                 AND a.date_echeance < CURDATE()
                 AND s.code NOT IN ('EXECUTEE', 'CLOTUREE', 'ANNULEE')
            THEN 1
            ELSE 0
        END
    ) AS actions_en_retard,

    ROUND(
        COALESCE(AVG(a.taux_avancement), 0),
        2
    ) AS avancement_moyen

FROM entites e
INNER JOIN types_entite te
    ON te.id = e.type_entite_id
    AND te.code = 'DIRECTION'

LEFT JOIN entites parent
    ON parent.id = e.parent_id

LEFT JOIN entites descendants
    ON descendants.parent_id = e.id
    OR descendants.id = e.id

LEFT JOIN actions a
    ON a.entite_responsable_id = descendants.id

LEFT JOIN statuts s
    ON s.id = a.statut_id

GROUP BY
    parent.id,
    parent.code,
    parent.nom,
    e.id,
    e.code,
    e.nom;

-- ============================================================
-- 15. REQUETES UTILES POUR EXPLORER LA HIERARCHIE
-- ============================================================

-- Arbre de niveau 1 à 4 :
--
-- SELECT
--     dg.nom AS direction_generale,
--     d.nom AS direction,
--     s.nom AS service,
--     b.nom AS bureau
-- FROM entites dg
-- LEFT JOIN entites d
--     ON d.parent_id = dg.id
-- LEFT JOIN entites s
--     ON s.parent_id = d.id
-- LEFT JOIN entites b
--     ON b.parent_id = s.id
-- WHERE dg.type_entite_id = (
--     SELECT id FROM types_entite
--     WHERE code = 'DIRECTION_GENERALE'
-- );

-- Pour parcourir une hiérarchie de profondeur variable avec MySQL 8 :
--
-- WITH RECURSIVE arbre AS (
--     SELECT
--         id,
--         code,
--         nom,
--         type_entite_id,
--         parent_id,
--         0 AS profondeur
--     FROM entites
--     WHERE parent_id IS NULL
--
--     UNION ALL
--
--     SELECT
--         e.id,
--         e.code,
--         e.nom,
--         e.type_entite_id,
--         e.parent_id,
--         a.profondeur + 1
--     FROM entites e
--     INNER JOIN arbre a
--         ON e.parent_id = a.id
-- )
-- SELECT *
-- FROM arbre
-- ORDER BY profondeur, nom;

-- ============================================================
-- FIN DU MODELE
-- ============================================================
