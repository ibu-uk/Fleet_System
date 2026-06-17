-- ============================================================
--  Fleet Management — Services Enhancement v3
--  Run this AFTER setup.sql and setup_v2.sql
--  Adds: Service tracking, free service logic, driver notifications
-- ============================================================

USE `fleet_management`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Add service tracking fields to vehicles table
-- ------------------------------------------------------------
ALTER TABLE `vehicles`
  ADD COLUMN `service_counter` INT DEFAULT 0 COMMENT 'Number of services completed (every 1000km)',
  ADD COLUMN `free_service_km_threshold` INT DEFAULT NULL COMMENT 'KM threshold for free service (e.g., 5000 = every 5000km)',
  ADD COLUMN `free_service_driver_id` INT DEFAULT NULL COMMENT 'Driver ID who gets free service for this vehicle',
  ADD COLUMN `last_service_km` INT DEFAULT 0 COMMENT 'KM at last service',
  ADD COLUMN `km_since_last_service` INT DEFAULT 0 COMMENT 'KM driven since last service',
  ADD COLUMN `free_service_counter` INT DEFAULT 0 COMMENT 'Number of free services used';

-- ------------------------------------------------------------
-- Add notification fields to vehicle_services
-- ------------------------------------------------------------
ALTER TABLE `vehicle_services`
  ADD COLUMN `is_free_service` TINYINT(1) DEFAULT 0 COMMENT '1 if this was a free service',
  ADD COLUMN `driver_notified` TINYINT(1) DEFAULT 0 COMMENT '1 if driver was notified about this service',
  ADD COLUMN `driver_notified_at` DATETIME NULL DEFAULT NULL;

-- ------------------------------------------------------------
-- Create service notifications table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_notifications` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT          NOT NULL,
  `driver_id`       INT          NOT NULL,
  `service_id`      INT          DEFAULT NULL COMMENT 'Linked service record if completed',
  `notification_type` ENUM('upcoming','overdue','free_service','reminder') DEFAULT 'upcoming',
  `message`         TEXT         DEFAULT NULL,
  `km_at_notification` INT        DEFAULT NULL,
  `due_km`          INT          DEFAULT NULL,
  `is_read`         TINYINT(1)   DEFAULT 0,
  `read_at`         DATETIME     NULL DEFAULT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_vehicle` (`vehicle_id`),
  KEY `idx_notif_driver` (`driver_id`),
  KEY `idx_notif_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- FOREIGN KEYS
-- ------------------------------------------------------------
ALTER TABLE `service_notifications`
  ADD CONSTRAINT `fk_sn_veh` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sn_drv` FOREIGN KEY (`driver_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sn_svc` FOREIGN KEY (`service_id`) REFERENCES `vehicle_services`(`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
