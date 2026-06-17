-- ============================================================
--  Fleet Management — Users & Authentication
--  Run this AFTER setup.sql and setup_v2.sql
-- ============================================================

USE `fleet_management`;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `full_name_en`  VARCHAR(100) NOT NULL,
  `full_name_ar`  VARCHAR(100) DEFAULT NULL,
  `email`         VARCHAR(120) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin','manager','viewer') DEFAULT 'viewer',
  `status`        ENUM('active','inactive','suspended') DEFAULT 'active',
  `last_login`    DATETIME     NULL DEFAULT NULL,
  `last_activity` DATETIME     NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  Default admin — password will be set by setup_password.php
--  Run setup_password.php ONCE after importing this SQL
-- ============================================================
INSERT INTO `users` (`username`, `full_name_en`, `full_name_ar`, `email`, `password_hash`, `role`, `status`)
VALUES (
  'admin',
  'System Administrator',
  'مدير النظام',
  'admin@fleet.kw',
  'PLACEHOLDER',
  'admin',
  'active'
);
