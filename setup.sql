-- ============================================================
--  Fleet Management System — Database Setup
--  Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `fleet_management`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `fleet_management`;

-- ------------------------------------------------------------
-- DUTY LOCATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `duty_locations` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `name_en`    VARCHAR(100) NOT NULL,
  `name_ar`    VARCHAR(100) DEFAULT NULL,
  `city_en`    VARCHAR(100) DEFAULT NULL,
  `city_ar`    VARCHAR(100) DEFAULT NULL,
  `status`     ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- EMPLOYEES / DRIVERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employees` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `emp_id`           VARCHAR(20)  NOT NULL,
  `name_en`          VARCHAR(100) NOT NULL,
  `name_ar`          VARCHAR(100) DEFAULT NULL,
  `phone`            VARCHAR(20)  DEFAULT NULL,
  `whatsapp`         VARCHAR(20)  DEFAULT NULL,
  `email`            VARCHAR(100) DEFAULT NULL,
  `nationality`      VARCHAR(60)  DEFAULT NULL,
  `civil_id`         VARCHAR(20)  DEFAULT NULL,
  `civil_id_expiry`  DATE         DEFAULT NULL,
  `passport_number`  VARCHAR(20)  DEFAULT NULL,
  `passport_expiry`  DATE         DEFAULT NULL,
  `license_number`   VARCHAR(50)  DEFAULT NULL,
  `license_type`     VARCHAR(20)  DEFAULT NULL,
  `license_expiry`   DATE         DEFAULT NULL,
  `duty_location_id` INT          DEFAULT NULL,
  `platform`         VARCHAR(20)  DEFAULT NULL COMMENT 'talabat|keeta|both',
  `status`           ENUM('active','inactive','suspended','on_leave') DEFAULT 'active',
  `join_date`        DATE         DEFAULT NULL,
  `notes`            TEXT         DEFAULT NULL,
  `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_id` (`emp_id`),
  KEY `idx_location` (`duty_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VEHICLES (cars & bikes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id`                  INT           NOT NULL AUTO_INCREMENT,
  `type`                ENUM('car','bike') NOT NULL DEFAULT 'car',
  `make`                VARCHAR(60)   NOT NULL,
  `model`               VARCHAR(60)   NOT NULL,
  `year`                SMALLINT      DEFAULT NULL,
  `color_en`            VARCHAR(40)   DEFAULT NULL,
  `color_ar`            VARCHAR(40)   DEFAULT NULL,
  `plate_number`        VARCHAR(20)   NOT NULL,
  `chassis_number`      VARCHAR(60)   DEFAULT NULL,
  `engine_number`       VARCHAR(60)   DEFAULT NULL,
  `current_km`          INT           DEFAULT 0,
  `first_service_km`    INT           DEFAULT NULL,
  `service_interval_km` INT           DEFAULT 5000,
  `current_driver_id`   INT           DEFAULT NULL,
  `platform`            VARCHAR(20)   DEFAULT NULL COMMENT 'talabat|keeta|both',
  `status`              ENUM('active','inactive','in_service','accident','sold') DEFAULT 'active',
  `purchase_date`       DATE          DEFAULT NULL,
  `purchase_price`      DECIMAL(10,2) DEFAULT NULL,
  `notes`               TEXT          DEFAULT NULL,
  `created_at`          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plate` (`plate_number`),
  KEY `idx_driver` (`current_driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DRIVER ASSIGNMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `driver_assignments` (
  `id`               INT  NOT NULL AUTO_INCREMENT,
  `vehicle_id`       INT  NOT NULL,
  `employee_id`      INT  NOT NULL,
  `assigned_date`    DATE NOT NULL,
  `unassigned_date`  DATE DEFAULT NULL,
  `duty_location_id` INT  DEFAULT NULL,
  `shift`            ENUM('morning','evening','night','full_day') DEFAULT 'full_day',
  `notes`            TEXT DEFAULT NULL,
  `status`           ENUM('active','ended') DEFAULT 'active',
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_da_vehicle`  (`vehicle_id`),
  KEY `idx_da_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VEHICLE SERVICES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicle_services` (
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
  KEY `idx_sv_vehicle` (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VEHICLE INSURANCE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicle_insurance` (
  `id`                INT           NOT NULL AUTO_INCREMENT,
  `vehicle_id`        INT           NOT NULL,
  `insurance_company` VARCHAR(120)  NOT NULL,
  `policy_number`     VARCHAR(60)   DEFAULT NULL,
  `insurance_type`    ENUM('comprehensive','third_party','fire_theft') DEFAULT 'comprehensive',
  `start_date`        DATE          NOT NULL,
  `expiry_date`       DATE          NOT NULL,
  `amount`            DECIMAL(10,2) DEFAULT NULL,
  `status`            ENUM('active','expired','cancelled','renewed') DEFAULT 'active',
  `notes`             TEXT          DEFAULT NULL,
  `created_at`        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ins_vehicle` (`vehicle_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VEHICLE ACCIDENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vehicle_accidents` (
  `id`                      INT           NOT NULL AUTO_INCREMENT,
  `vehicle_id`              INT           NOT NULL,
  `driver_id`               INT           DEFAULT NULL,
  `accident_date`           DATE          NOT NULL,
  `accident_time`           TIME          DEFAULT NULL,
  `location`                VARCHAR(200)  DEFAULT NULL,
  `description`             TEXT          DEFAULT NULL,
  `damage_level`            ENUM('minor','moderate','severe','total_loss') DEFAULT 'minor',
  `repair_cost`             DECIMAL(10,2) DEFAULT NULL,
  `insurance_claim`         TINYINT(1)    DEFAULT 0,
  `claim_number`            VARCHAR(60)   DEFAULT NULL,
  `at_fault`                ENUM('our_driver','third_party','shared','unknown') DEFAULT 'unknown',
  `police_report`           TINYINT(1)    DEFAULT 0,
  `police_report_number`    VARCHAR(60)   DEFAULT NULL,
  `status`                  ENUM('reported','under_assessment','under_repair',
                                  'repaired','written_off','closed') DEFAULT 'reported',
  `repair_completion_date`  DATE          DEFAULT NULL,
  `notes`                   TEXT          DEFAULT NULL,
  `created_at`              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ac_vehicle` (`vehicle_id`),
  KEY `idx_ac_driver`  (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- FOREIGN KEYS
-- ------------------------------------------------------------
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_loc`
  FOREIGN KEY (`duty_location_id`) REFERENCES `duty_locations`(`id`) ON DELETE SET NULL;

ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_veh_driver`
  FOREIGN KEY (`current_driver_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL;

ALTER TABLE `driver_assignments`
  ADD CONSTRAINT `fk_da_veh` FOREIGN KEY (`vehicle_id`)  REFERENCES `vehicles`(`id`)   ON DELETE CASCADE,
  ADD CONSTRAINT `fk_da_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `fk_da_loc` FOREIGN KEY (`duty_location_id`) REFERENCES `duty_locations`(`id`) ON DELETE SET NULL;

ALTER TABLE `vehicle_services`
  ADD CONSTRAINT `fk_svc_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE;

ALTER TABLE `vehicle_insurance`
  ADD CONSTRAINT `fk_ins_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE;

ALTER TABLE `vehicle_accidents`
  ADD CONSTRAINT `fk_acc_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_acc_drv` FOREIGN KEY (`driver_id`)  REFERENCES `employees`(`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- SEED DATA — Duty Locations (Kuwait)
-- ------------------------------------------------------------
INSERT INTO `duty_locations` (`name_en`,`name_ar`,`city_en`,`city_ar`) VALUES
('Salmiya',     'السالمية',   'Kuwait City','مدينة الكويت'),
('Hawalli',     'حولي',        'Kuwait City','مدينة الكويت'),
('Rumaithiya',  'الرميثية',   'Kuwait City','مدينة الكويت'),
('Mishref',     'مشرف',        'Kuwait City','مدينة الكويت'),
('Farwaniya',   'الفروانية',  'Farwaniya',  'الفروانية'),
('Firdous',     'الفردوس',    'Farwaniya',  'الفروانية'),
('Ahmadi',      'الأحمدي',    'Ahmadi',     'الأحمدي'),
('Fahaheel',    'الفحيحيل',   'Ahmadi',     'الأحمدي'),
('Mangaf',      'المنقف',      'Ahmadi',     'الأحمدي'),
('Abu Halifa',  'أبو حليفة',  'Ahmadi',     'الأحمدي'),
('Fintas',      'الفنطاس',    'Ahmadi',     'الأحمدي'),
('Mahboula',    'المهبولة',   'Ahmadi',     'الأحمدي'),
('Jahra',       'الجهراء',    'Jahra',      'الجهراء'),
('Sulaibiya',   'الصليبية',   'Jahra',      'الجهراء'),
('Mubarak Al-Kabeer','مبارك الكبير','Mubarak Al-Kabeer','مبارك الكبير');

SET FOREIGN_KEY_CHECKS = 1;
