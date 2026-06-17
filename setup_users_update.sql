-- ============================================================
--  Update users table for driver portal support
--  Adds employee_id column and driver role
-- ============================================================

USE `Fleet_management`;

-- Add employee_id column to link users to employees (skip if already exists)
-- ALTER TABLE `users`
--   ADD COLUMN `employee_id` INT DEFAULT NULL COMMENT 'Link to employees table (for driver portal)' AFTER `role`,
--   ADD KEY `idx_employee_id` (`employee_id`);

-- Update role ENUM to include driver
-- Note: MySQL doesn't support ALTER ENUM directly, need to recreate
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','manager','viewer','driver') DEFAULT 'viewer';
