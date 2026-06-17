-- ============================================================
--  Fleet Management — v2 Additions
--  Run this AFTER setup.sql
--  Adds: inspections, inspection_images, hygiene_approvals,
--        reminders, notifications
-- ============================================================

USE `fleet_management`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop in reverse FK order so re-import is safe
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `reminders`;
DROP TABLE IF EXISTS `inspection_images`;
DROP TABLE IF EXISTS `hygiene_approvals`;
DROP TABLE IF EXISTS `vehicle_inspections`;

-- ------------------------------------------------------------
-- VEHICLE INSPECTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicle_inspections` (
  `id`                INT           NOT NULL AUTO_INCREMENT,
  `vehicle_id`        INT           NOT NULL,
  `driver_id`         INT           DEFAULT NULL,
  `inspector_name`    VARCHAR(100)  DEFAULT NULL,
  `inspection_type`   ENUM('pre_trip','post_trip','routine','incident','handover') DEFAULT 'routine',
  `inspection_date`   DATE          NOT NULL,
  `inspection_time`   TIME          DEFAULT NULL,
  `overall_condition` ENUM('good','fair','poor','critical') DEFAULT 'good',

  -- Damage zones (text description per zone)
  `front_damage`      TEXT          DEFAULT NULL,
  `rear_damage`       TEXT          DEFAULT NULL,
  `left_damage`       TEXT          DEFAULT NULL,
  `right_damage`      TEXT          DEFAULT NULL,
  `interior_damage`   TEXT          DEFAULT NULL,
  `engine_damage`     TEXT          DEFAULT NULL,
  `top_damage`        TEXT          DEFAULT NULL,

  -- Checklist (1=OK, 0=issue)
  `check_lights`      TINYINT(1)    DEFAULT 1,
  `check_brakes`      TINYINT(1)    DEFAULT 1,
  `check_tires`       TINYINT(1)    DEFAULT 1,
  `check_mirrors`     TINYINT(1)    DEFAULT 1,
  `check_ac`          TINYINT(1)    DEFAULT 1,
  `check_fuel`        TINYINT(1)    DEFAULT 1,
  `check_cleanliness` TINYINT(1)    DEFAULT 1,
  `check_documents`   TINYINT(1)    DEFAULT 1,

  `current_km`        INT           DEFAULT NULL,
  `fuel_level`        ENUM('empty','quarter','half','three_quarter','full') DEFAULT 'half',

  `damage_description` TEXT         DEFAULT NULL,
  `action_required`   TEXT          DEFAULT NULL,
  `notes`             TEXT          DEFAULT NULL,

  `status`            ENUM('pending_review','reviewed','action_required','closed') DEFAULT 'pending_review',
  `driver_notified`   TINYINT(1)    DEFAULT 0,
  `driver_notified_at` DATETIME     NULL DEFAULT NULL,
  `reviewed_by`       VARCHAR(100)  DEFAULT NULL,
  `reviewed_at`       DATETIME      NULL DEFAULT NULL,

  `created_at`        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_insp_vehicle` (`vehicle_id`),
  KEY `idx_insp_driver`  (`driver_id`),
  KEY `idx_insp_date`    (`inspection_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- INSPECTION IMAGES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inspection_images` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `inspection_id` INT          NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `image_zone`    ENUM('front','rear','left','right','interior','engine','top','overview','damage','other') DEFAULT 'other',
  `caption`       VARCHAR(200) DEFAULT NULL,
  `file_size`     INT          DEFAULT NULL,
  `uploaded_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_img_insp` (`inspection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- HYGIENE / AUTHORITY APPROVALS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hygiene_approvals` (
  `id`               INT           NOT NULL AUTO_INCREMENT,
  `vehicle_id`       INT           DEFAULT NULL COMMENT 'NULL = fleet-wide approval',
  `approval_number`  VARCHAR(80)   DEFAULT NULL,
  `approval_type`    ENUM('food_hygiene','vehicle_cleanliness','delivery_permit',
                          'health_certificate','municipality','other') DEFAULT 'food_hygiene',
  `issued_by`        VARCHAR(150)  NOT NULL,
  `issue_date`       DATE          NOT NULL,
  `expiry_date`      DATE          NOT NULL,
  `filename`         VARCHAR(255)  DEFAULT NULL COMMENT 'stored PDF filename',
  `original_filename` VARCHAR(255) DEFAULT NULL,
  `file_size`        INT           DEFAULT NULL,
  `status`           ENUM('active','expired','pending_renewal','suspended') DEFAULT 'active',
  `notes`            TEXT          DEFAULT NULL,
  `created_at`       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_appr_vehicle` (`vehicle_id`),
  KEY `idx_appr_expiry`  (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- REMINDERS (configurable per-record alerts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reminders` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `reminder_type`   ENUM('insurance','service','license','civil_id','passport',
                         'approval','inspection','custom') NOT NULL,
  `title`           VARCHAR(200) DEFAULT NULL,
  `reference_table` VARCHAR(60)  DEFAULT NULL,
  `reference_id`    INT          DEFAULT NULL,
  `vehicle_id`      INT          DEFAULT NULL,
  `employee_id`     INT          DEFAULT NULL,
  `remind_date`     DATE         NOT NULL,
  `message`         TEXT         DEFAULT NULL,
  `priority`        ENUM('low','medium','high','critical') DEFAULT 'medium',
  `status`          ENUM('pending','acknowledged','snoozed','completed','dismissed') DEFAULT 'pending',
  `snoozed_until`   DATE         DEFAULT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rem_date`    (`remind_date`),
  KEY `idx_rem_vehicle` (`vehicle_id`),
  KEY `idx_rem_type`    (`reminder_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- NOTIFICATIONS LOG (in-app notification feed)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `type`        VARCHAR(60)  NOT NULL COMMENT 'insurance_expiry, service_due, etc.',
  `title`       VARCHAR(200) NOT NULL,
  `message`     TEXT         DEFAULT NULL,
  `icon`        VARCHAR(40)  DEFAULT 'fa-bell',
  `color`       VARCHAR(20)  DEFAULT 'warning',
  `link`        VARCHAR(200) DEFAULT NULL,
  `is_read`     TINYINT(1)   DEFAULT 0,
  `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- FOREIGN KEYS
-- ------------------------------------------------------------
ALTER TABLE `vehicle_inspections`
  ADD CONSTRAINT `fk_insp_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_insp_drv` FOREIGN KEY (`driver_id`)  REFERENCES `employees`(`id`) ON DELETE SET NULL;

ALTER TABLE `inspection_images`
  ADD CONSTRAINT `fk_img_insp` FOREIGN KEY (`inspection_id`) REFERENCES `vehicle_inspections`(`id`) ON DELETE CASCADE;

ALTER TABLE `hygiene_approvals`
  ADD CONSTRAINT `fk_appr_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL;

ALTER TABLE `reminders`
  ADD CONSTRAINT `fk_rem_veh` FOREIGN KEY (`vehicle_id`)  REFERENCES `vehicles`(`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rem_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
