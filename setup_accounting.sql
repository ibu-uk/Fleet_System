-- ============================================================
--  Fleet Management — Accounting Module
--  Run this AFTER setup.sql, setup_v2.sql, setup_services_v3.sql, setup_gps_fuel_trips.sql
--  Adds: Petty Cash, Expenses, Reports, Accountant Role
-- ============================================================

USE `Fleet_management`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Update users table to add accountant role
-- ------------------------------------------------------------
ALTER TABLE `users` 
MODIFY COLUMN `role` ENUM('admin','manager','accountant','viewer') DEFAULT 'viewer';

-- ------------------------------------------------------------
-- Petty Cash Tables
-- ------------------------------------------------------------

-- Cash ledger (tracks cash balance)
CREATE TABLE IF NOT EXISTS `cash_ledger` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `opening_balance` DECIMAL(12,2) DEFAULT 0.00,
  `current_balance` DECIMAL(12,2) DEFAULT 0.00,
  `currency`        VARCHAR(3)   DEFAULT 'KWD',
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cash transactions (deposits, withdrawals)
CREATE TABLE IF NOT EXISTS `cash_transactions` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `transaction_type` ENUM('deposit','withdrawal') NOT NULL,
  `amount`          DECIMAL(12,2) NOT NULL,
  `balance_after`    DECIMAL(12,2) NOT NULL,
  `description`     VARCHAR(255) DEFAULT NULL,
  `reference_type`  VARCHAR(50)  DEFAULT NULL COMMENT 'expense, salary, etc.',
  `reference_id`    INT          DEFAULT NULL,
  `created_by`      INT          NOT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cash_date` (`created_at`),
  KEY `idx_cash_type` (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Expense Tables
-- ------------------------------------------------------------

-- Expense categories
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `name_en`         VARCHAR(100) NOT NULL,
  `name_ar`         VARCHAR(100) DEFAULT NULL,
  `description_en`  TEXT         DEFAULT NULL,
  `description_ar`  TEXT         DEFAULT NULL,
  `status`          ENUM('active','inactive') DEFAULT 'active',
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name_en` (`name_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses
CREATE TABLE IF NOT EXISTS `expenses` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `category_id`     INT          NOT NULL,
  `expense_date`    DATE         NOT NULL,
  `amount`          DECIMAL(12,2) NOT NULL,
  `currency`        VARCHAR(3)   DEFAULT 'KWD',
  `vendor_name`     VARCHAR(200) DEFAULT NULL,
  `vendor_contact`  VARCHAR(100) DEFAULT NULL,
  `invoice_number`  VARCHAR(100) DEFAULT NULL,
  `payment_method`  ENUM('cash','bank_transfer','card','cheque') DEFAULT 'cash',
  `description_en`  TEXT         DEFAULT NULL,
  `description_ar`  TEXT         DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `created_by`      INT          NOT NULL,
  `approved_by`     INT          DEFAULT NULL,
  `approved_at`     DATETIME     DEFAULT NULL,
  `status`          ENUM('pending','approved','rejected') DEFAULT 'pending',
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense_date`     (`expense_date`),
  KEY `idx_expense_category`  (`category_id`),
  KEY `idx_expense_status`    (`status`),
  KEY `idx_expense_vendor`    (`vendor_name`(50)),
  KEY `idx_expense_date_stat` (`expense_date`, `status`),
  KEY `idx_expense_cat_date`  (`category_id`, `expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expense attachments
CREATE TABLE IF NOT EXISTS `expense_attachments` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `expense_id`      INT          NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_path`       VARCHAR(500) NOT NULL,
  `file_size`       INT          DEFAULT NULL,
  `file_type`       VARCHAR(50)  DEFAULT NULL,
  `uploaded_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attach_expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Insert default expense categories
-- ------------------------------------------------------------
INSERT INTO `expense_categories` (`name_en`, `name_ar`, `description_en`, `description_ar`) VALUES
('Fuel', 'وقود', 'Vehicle fuel expenses', 'مصاريف وقود المركبات'),
('Maintenance', 'صيانة', 'Vehicle maintenance and repairs', 'صيانة وإصلاح المركبات'),
('Salaries', 'رواتب', 'Employee salaries and wages', 'رواتب وأجور الموظفين'),
('Office Supplies', 'لوازم مكتبية', 'Office supplies and equipment', 'لوازم ومعدات المكتب'),
('Utilities', 'مرافق', 'Electricity, water, internet', 'كهرباء، ماء، إنترنت'),
('Rent', 'إيجار', 'Office or warehouse rent', 'إيجار المكتب أو المستودع'),
('Insurance', 'تأمين', 'Insurance premiums', 'أقساط التأمين'),
('Travel', 'سفر', 'Travel and accommodation', 'سفر وإقامة'),
('Vehicle Parts', 'قطع غيار', 'Spare parts for vehicles', 'قطع غيار للمركبات'),
('Tolls & Parking', 'رسوم الطريق', 'Toll charges and parking fees', 'رسوم الطريق ومواقف السيارات'),
('Other', 'أخرى', 'Other miscellaneous expenses', 'مصاريف متنوعة أخرى')
ON DUPLICATE KEY UPDATE name_en=name_en;

-- ------------------------------------------------------------
-- Initialize cash ledger if empty
-- ------------------------------------------------------------
INSERT INTO `cash_ledger` (`opening_balance`, `current_balance`, `currency`)
SELECT 0, 0, 'KWD'
WHERE NOT EXISTS (SELECT 1 FROM `cash_ledger` WHERE id=1);

-- ------------------------------------------------------------
-- Penalties table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `penalties` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id`       INT UNSIGNED NOT NULL,
  `employee_id`      INT UNSIGNED DEFAULT NULL,
  `penalty_type`     ENUM('over_speed','wrong_parking','belt','signal_crossing','phone_use','no_license','expired_license','reckless_driving','no_insurance','expired_registration','wrong_turn','no_plate','tinted_windows','other') NOT NULL DEFAULT 'other',
  `penalty_date`     DATE NOT NULL,
  `amount`           DECIMAL(10,3) DEFAULT NULL,
  `reference_number` VARCHAR(100)  DEFAULT NULL,
  `status`           ENUM('pending','paid','disputed') NOT NULL DEFAULT 'pending',
  `notes`            TEXT,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pen_vehicle`  (`vehicle_id`),
  KEY `idx_pen_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Vehicle Maintenance table (independent copy of services)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicle_maintenance` (
  `id`              INT           NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT           NOT NULL,
  `service_date`    DATE          NOT NULL,
  `service_km`      INT           DEFAULT NULL,
  `service_type`    ENUM('oil_change','tire_rotation','tire_replacement',
                         'brake','engine','transmission','battery',
                         'ac','general_checkup','major_service','other') NOT NULL,
  `service_number`  INT           DEFAULT 1,
  `next_service_km` INT           DEFAULT NULL,
  `cost`            DECIMAL(10,2) DEFAULT NULL,
  `garage_name`     VARCHAR(100)  DEFAULT NULL,
  `performed_by`    VARCHAR(100)  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mt_vehicle` (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add car_company to vehicles (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vehicles' AND column_name='car_company');
SET @sql = IF(@col_exists=0, 'ALTER TABLE vehicles ADD COLUMN car_company VARCHAR(100) DEFAULT NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add rc_date to vehicles (if not exists)
SET @col_exists3 = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vehicles' AND column_name='rc_date');
SET @sql3 = IF(@col_exists3=0, 'ALTER TABLE vehicles ADD COLUMN rc_date DATE DEFAULT NULL AFTER car_company', 'SELECT 1');
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- Add food_card_expiry to vehicles (if not exists)
SET @col_exists4 = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vehicles' AND column_name='food_card_expiry');
SET @sql4 = IF(@col_exists4=0, 'ALTER TABLE vehicles ADD COLUMN food_card_expiry DATE DEFAULT NULL AFTER rc_date', 'SELECT 1');
PREPARE stmt4 FROM @sql4; EXECUTE stmt4; DEALLOCATE PREPARE stmt4;

-- Add municipality_expiry to vehicles (if not exists)
SET @col_exists5 = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vehicles' AND column_name='municipality_expiry');
SET @sql5 = IF(@col_exists5=0, 'ALTER TABLE vehicles ADD COLUMN municipality_expiry DATE DEFAULT NULL AFTER food_card_expiry', 'SELECT 1');
PREPARE stmt5 FROM @sql5; EXECUTE stmt5; DEALLOCATE PREPARE stmt5;

-- Add residency_company to employees (if not exists)
SET @col_exists2 = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='residency_company');
SET @sql2 = IF(@col_exists2=0, 'ALTER TABLE employees ADD COLUMN residency_company VARCHAR(100) DEFAULT NULL AFTER platform', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET FOREIGN_KEY_CHECKS = 1;
