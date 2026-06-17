-- ============================================================
--  Fleet System — GPS, Fuel & Trips Enhancement
--  Run this AFTER setup.sql, setup_v2.sql, and setup_services_v3.sql
--  Adds: GPS tracking, fuel management, trip management
-- ============================================================

USE `fleet_System`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- GPS Tracking Tables
-- ------------------------------------------------------------

-- Vehicle locations (live tracking)
CREATE TABLE IF NOT EXISTS `vehicle_locations` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT          NOT NULL,
  `latitude`        DECIMAL(10,8) NOT NULL,
  `longitude`       DECIMAL(11,8) NOT NULL,
  `speed`           DECIMAL(5,2) DEFAULT NULL COMMENT 'Speed in km/h',
  `heading`         DECIMAL(5,2) DEFAULT NULL COMMENT 'Direction in degrees',
  `altitude`        DECIMAL(8,2) DEFAULT NULL COMMENT 'Altitude in meters',
  `accuracy`        DECIMAL(5,2) DEFAULT NULL COMMENT 'GPS accuracy in meters',
  `location_time`   DATETIME     NOT NULL,
  `odometer`        INT          DEFAULT NULL COMMENT 'Vehicle odometer reading',
  `engine_status`   TINYINT(1)   DEFAULT NULL COMMENT '1 if engine is on',
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_loc_vehicle` (`vehicle_id`),
  KEY `idx_loc_time` (`location_time`),
  KEY `idx_loc_vehicle_time` (`vehicle_id`, `location_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vehicle routes (trip history)
CREATE TABLE IF NOT EXISTS `vehicle_routes` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT          NOT NULL,
  `trip_id`         INT          DEFAULT NULL,
  `latitude`        DECIMAL(10,8) NOT NULL,
  `longitude`       DECIMAL(11,8) NOT NULL,
  `speed`           DECIMAL(5,2) DEFAULT NULL,
  `route_time`      DATETIME     NOT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_route_vehicle` (`vehicle_id`),
  KEY `idx_route_trip` (`trip_id`),
  KEY `idx_route_time` (`route_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Geofences (geographic boundaries)
CREATE TABLE IF NOT EXISTS `geofences` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `name_en`         VARCHAR(100) NOT NULL,
  `name_ar`         VARCHAR(100) DEFAULT NULL,
  `type`            ENUM('circle','polygon') DEFAULT 'circle',
  `center_lat`      DECIMAL(10,8) DEFAULT NULL COMMENT 'Center latitude for circle',
  `center_lng`      DECIMAL(11,8) DEFAULT NULL COMMENT 'Center longitude for circle',
  `radius`          DECIMAL(10,2) DEFAULT NULL COMMENT 'Radius in meters for circle',
  `coordinates`     TEXT         DEFAULT NULL COMMENT 'JSON polygon coordinates',
  `alert_on_entry`  TINYINT(1)   DEFAULT 1,
  `alert_on_exit`   TINYINT(1)   DEFAULT 1,
  `status`          ENUM('active','inactive') DEFAULT 'active',
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Geofence events (entry/exit logs)
CREATE TABLE IF NOT EXISTS `geofence_events` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT          NOT NULL,
  `geofence_id`     INT          NOT NULL,
  `event_type`      ENUM('entry','exit') NOT NULL,
  `latitude`        DECIMAL(10,8) NOT NULL,
  `longitude`       DECIMAL(11,8) NOT NULL,
  `event_time`      DATETIME     NOT NULL,
  `speed`           DECIMAL(5,2) DEFAULT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_geofence_vehicle` (`vehicle_id`),
  KEY `idx_geofence_id` (`geofence_id`),
  KEY `idx_geofence_time` (`event_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Fuel Management Tables
-- ------------------------------------------------------------

-- Fuel purchase records
CREATE TABLE IF NOT EXISTS `fuel_records` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT          NOT NULL,
  `driver_id`       INT          DEFAULT NULL,
  `fuel_date`       DATE         NOT NULL,
  `fuel_type`       ENUM('petrol','diesel','cng','electric') DEFAULT 'petrol',
  `liters`          DECIMAL(10,2) NOT NULL,
  `price_per_liter` DECIMAL(10,3) NOT NULL,
  `total_cost`      DECIMAL(10,3) NOT NULL,
  `station_name`    VARCHAR(100) DEFAULT NULL,
  `station_location` VARCHAR(100) DEFAULT NULL,
  `odometer`        INT          DEFAULT NULL COMMENT 'Odometer reading at fueling',
  `full_tank`       TINYINT(1)   DEFAULT 0 COMMENT '1 if tank was filled completely',
  `notes`           TEXT         DEFAULT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fuel_vehicle` (`vehicle_id`),
  KEY `idx_fuel_driver` (`driver_id`),
  KEY `idx_fuel_date` (`fuel_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Trip Management Tables
-- ------------------------------------------------------------

-- Trips
CREATE TABLE IF NOT EXISTS `trips` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `vehicle_id`      INT          NOT NULL,
  `driver_id`       INT          NOT NULL,
  `trip_number`     VARCHAR(20)  NOT NULL,
  `start_time`      DATETIME     NOT NULL,
  `end_time`        DATETIME     DEFAULT NULL,
  `start_location`  VARCHAR(200) DEFAULT NULL,
  `end_location`    VARCHAR(200) DEFAULT NULL,
  `start_odometer`  INT          DEFAULT NULL,
  `end_odometer`    INT          DEFAULT NULL,
  `distance_km`     DECIMAL(10,2) DEFAULT NULL,
  `purpose`         VARCHAR(200) DEFAULT NULL,
  `status`          ENUM('planned','in_progress','completed','cancelled') DEFAULT 'planned',
  `notes`           TEXT         DEFAULT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_number` (`trip_number`),
  KEY `idx_trip_vehicle` (`vehicle_id`),
  KEY `idx_trip_driver` (`driver_id`),
  KEY `idx_trip_status` (`status`),
  KEY `idx_trip_date` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trip expenses
CREATE TABLE IF NOT EXISTS `trip_expenses` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `trip_id`         INT          NOT NULL,
  `expense_type`    ENUM('fuel','toll','parking','repair','other') NOT NULL,
  `amount`          DECIMAL(10,3) NOT NULL,
  `currency`        VARCHAR(3)   DEFAULT 'KWD',
  `expense_date`    DATE         NOT NULL,
  `description`     VARCHAR(200) DEFAULT NULL,
  `receipt_image`   VARCHAR(255) DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expense_trip` (`trip_id`),
  KEY `idx_expense_type` (`expense_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- FOREIGN KEYS
-- ------------------------------------------------------------

-- Helper procedure to add foreign key only if it doesn't exist
DELIMITER $$
DROP PROCEDURE IF EXISTS add_fk_if_not_exists$$
CREATE PROCEDURE add_fk_if_not_exists(
  IN p_table VARCHAR(64),
  IN p_constraint VARCHAR(64),
  IN p_sql TEXT
)
BEGIN
  DECLARE fk_count INT;
  SELECT COUNT(*) INTO fk_count
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = p_table
    AND constraint_name = p_constraint
    AND constraint_type = 'FOREIGN KEY';
  
  IF fk_count = 0 THEN
    SET @sql = p_sql;
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

-- GPS Tracking Foreign Keys
CALL add_fk_if_not_exists('vehicle_locations', 'fk_loc_vehicle',
  'ALTER TABLE `vehicle_locations` ADD CONSTRAINT `fk_loc_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE');

CALL add_fk_if_not_exists('vehicle_routes', 'fk_route_vehicle',
  'ALTER TABLE `vehicle_routes` ADD CONSTRAINT `fk_route_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE');

CALL add_fk_if_not_exists('vehicle_routes', 'fk_route_trip',
  'ALTER TABLE `vehicle_routes` ADD CONSTRAINT `fk_route_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips`(`id`) ON DELETE SET NULL');

CALL add_fk_if_not_exists('geofence_events', 'fk_geofence_vehicle',
  'ALTER TABLE `geofence_events` ADD CONSTRAINT `fk_geofence_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE');

CALL add_fk_if_not_exists('geofence_events', 'fk_geofence_geofence',
  'ALTER TABLE `geofence_events` ADD CONSTRAINT `fk_geofence_geofence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE');

-- Fuel Management Foreign Keys
CALL add_fk_if_not_exists('fuel_records', 'fk_fuel_vehicle',
  'ALTER TABLE `fuel_records` ADD CONSTRAINT `fk_fuel_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE');

CALL add_fk_if_not_exists('fuel_records', 'fk_fuel_driver',
  'ALTER TABLE `fuel_records` ADD CONSTRAINT `fk_fuel_driver` FOREIGN KEY (`driver_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL');

-- Trip Management Foreign Keys
CALL add_fk_if_not_exists('trips', 'fk_trip_vehicle',
  'ALTER TABLE `trips` ADD CONSTRAINT `fk_trip_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE');

CALL add_fk_if_not_exists('trips', 'fk_trip_driver',
  'ALTER TABLE `trips` ADD CONSTRAINT `fk_trip_driver` FOREIGN KEY (`driver_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE');

CALL add_fk_if_not_exists('trip_expenses', 'fk_expense_trip',
  'ALTER TABLE `trip_expenses` ADD CONSTRAINT `fk_expense_trip` FOREIGN KEY (`trip_id`) REFERENCES `trips`(`id`) ON DELETE CASCADE');

-- Clean up helper procedure
DROP PROCEDURE IF EXISTS add_fk_if_not_exists;

SET FOREIGN_KEY_CHECKS = 1;
