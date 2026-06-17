-- ============================================================
--  Fleet Management — Order Tracking, Driver Bonus & Petrol Cards
--  Run this AFTER all other setup_*.sql files
--  Adds: daily order tracking, configurable monthly bonus policy,
--        per-driver petrol card assignment & fuel utilisation
-- ============================================================

USE `Fleet_management`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- App-wide settings (key/value store)
-- Used for the global bonus policy (threshold, amount, on/off)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key`   VARCHAR(60)  NOT NULL,
  `setting_value` VARCHAR(255) DEFAULT NULL,
  `updated_at`    DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default bonus policy (management can change these anytime in Settings)
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('bonus_enabled',          '1'),    -- master on/off switch for bonuses
  ('bonus_monthly_target',   '450'),  -- orders/month required to qualify
  ('bonus_amount',           '50'),   -- flat bonus in KWD when target met
  ('daily_order_target',     '15')    -- informational daily goal per driver
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ------------------------------------------------------------
-- Per-driver columns on employees
-- ------------------------------------------------------------
-- Note: IF NOT EXISTS not supported in older MySQL versions.
-- These will fail silently if columns already exist.
ALTER TABLE `employees`
  ADD COLUMN `bonus_eligible`       TINYINT(1)  DEFAULT 0  COMMENT '1 = driver qualifies for monthly bonus',
  ADD COLUMN `monthly_order_target` INT         DEFAULT NULL COMMENT 'Per-driver override of global target',
  ADD COLUMN `petrol_card_number`   VARCHAR(50) DEFAULT NULL COMMENT 'Fuel card assigned to driver';

-- ------------------------------------------------------------
-- Daily driver orders
-- One row per driver / date / platform
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `driver_orders` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `driver_id`   INT          NOT NULL,
  `order_date`  DATE         NOT NULL,
  `platform`    ENUM('talabat','keeta','other') NOT NULL DEFAULT 'talabat',
  `order_count` INT          NOT NULL DEFAULT 0,
  `notes`       VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_driver_date_platform` (`driver_id`, `order_date`, `platform`),
  KEY `idx_orders_driver` (`driver_id`),
  KEY `idx_orders_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Monthly bonus log (calculated & approved per driver/month)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `driver_bonuses` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `driver_id`    INT          NOT NULL,
  `bonus_month`  DATE         NOT NULL COMMENT 'First day of the month, e.g. 2026-06-01',
  `total_orders` INT          NOT NULL DEFAULT 0,
  `target`       INT          NOT NULL DEFAULT 0,
  `bonus_amount` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `status`       ENUM('pending','approved','paid','cancelled') DEFAULT 'pending',
  `approved_by`  INT          DEFAULT NULL,
  `notes`        VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_driver_month` (`driver_id`, `bonus_month`),
  KEY `idx_bonus_driver` (`driver_id`),
  KEY `idx_bonus_month` (`bonus_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Petrol card number on fuel records (preserves history even if
-- the card is later reassigned to another driver)
-- ------------------------------------------------------------
ALTER TABLE `fuel_records`
  ADD COLUMN `card_number` VARCHAR(50) DEFAULT NULL COMMENT 'Petrol card used for this purchase';

-- ------------------------------------------------------------
-- FOREIGN KEYS
-- ------------------------------------------------------------
-- Note: Constraints already exist from previous run. Commented out to avoid duplicate key error.
-- ALTER TABLE `driver_orders`
--   ADD CONSTRAINT `fk_order_driver` FOREIGN KEY (`driver_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE;
-- ALTER TABLE `driver_bonuses`
--   ADD CONSTRAINT `fk_bonus_driver` FOREIGN KEY (`driver_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
