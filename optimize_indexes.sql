-- ============================================================
--  Fleet Management — Performance Index Optimization
--  Safe to run multiple times (skips existing indexes).
--  Target: smooth operation at 25,000+ records per table.
--  Run once in phpMyAdmin (SQL tab) on the fleet_management DB.
-- ============================================================

-- Helper procedure: add an index only if it doesn't already exist
DROP PROCEDURE IF EXISTS `add_index_if_missing`;
DELIMITER //
CREATE PROCEDURE `add_index_if_missing`(
  IN tbl  VARCHAR(64),
  IN idx  VARCHAR(64),
  IN cols VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = tbl
      AND index_name   = idx
  )
  AND EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = tbl
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` (', cols, ')');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

-- ---- EMPLOYEES (filters: status, platform; sort: name_en) ----
CALL add_index_if_missing('employees', 'idx_emp_status',   '`status`');
CALL add_index_if_missing('employees', 'idx_emp_platform', '`platform`');
CALL add_index_if_missing('employees', 'idx_emp_name',     '`name_en`');

-- ---- VEHICLES (filters: status, type, platform; sort: type+plate) ----
CALL add_index_if_missing('vehicles', 'idx_veh_status',       '`status`');
CALL add_index_if_missing('vehicles', 'idx_veh_type',         '`type`');
CALL add_index_if_missing('vehicles', 'idx_veh_platform',     '`platform`');
CALL add_index_if_missing('vehicles', 'idx_veh_type_plate',   '`type`,`plate_number`');

-- ---- VEHICLE SERVICES (filter: service_type; sort: service_date) ----
CALL add_index_if_missing('vehicle_services', 'idx_sv_date', '`service_date`');
CALL add_index_if_missing('vehicle_services', 'idx_sv_type', '`service_type`');
CALL add_index_if_missing('vehicle_services', 'idx_sv_veh_date', '`vehicle_id`,`service_date`');

-- ---- VEHICLE MAINTENANCE (new module) ----
CALL add_index_if_missing('vehicle_maintenance', 'idx_mt_date', '`service_date`');
CALL add_index_if_missing('vehicle_maintenance', 'idx_mt_type', '`service_type`');
CALL add_index_if_missing('vehicle_maintenance', 'idx_mt_veh_date', '`vehicle_id`,`service_date`');

-- ---- VEHICLE INSURANCE (filter/sort: expiry_date, status) ----
CALL add_index_if_missing('vehicle_insurance', 'idx_ins_expiry', '`expiry_date`');
CALL add_index_if_missing('vehicle_insurance', 'idx_ins_status', '`status`');

-- ---- VEHICLE ACCIDENTS (sort: accident_date; filter: status) ----
CALL add_index_if_missing('vehicle_accidents', 'idx_ac_date',   '`accident_date`');
CALL add_index_if_missing('vehicle_accidents', 'idx_ac_status', '`status`');

-- ---- PENALTIES (filters: type, status; sort: penalty_date) ----
CALL add_index_if_missing('penalties', 'idx_pen_date',   '`penalty_date`');
CALL add_index_if_missing('penalties', 'idx_pen_type',   '`penalty_type`');
CALL add_index_if_missing('penalties', 'idx_pen_status', '`status`');

-- ---- EXPENSES (heavy reporting: date, status, category, method, vendor) ----
CALL add_index_if_missing('expenses', 'idx_exp_date',     '`expense_date`');
CALL add_index_if_missing('expenses', 'idx_exp_status',   '`status`');
CALL add_index_if_missing('expenses', 'idx_exp_category', '`category_id`');
CALL add_index_if_missing('expenses', 'idx_exp_date_status', '`expense_date`,`status`');

-- ---- CASH TRANSACTIONS (reporting by created_at) ----
CALL add_index_if_missing('cash_transactions', 'idx_cash_created', '`created_at`');

-- ---- NOTIFICATIONS (feed by created_at) ----
CALL add_index_if_missing('notifications', 'idx_notif_created', '`created_at`');

-- ---- DRIVER ASSIGNMENTS (active-status lookups per employee/vehicle) ----
CALL add_index_if_missing('driver_assignments', 'idx_da_status',     '`status`');
CALL add_index_if_missing('driver_assignments', 'idx_da_emp_status', '`employee_id`,`status`');

-- Clean up helper
DROP PROCEDURE IF EXISTS `add_index_if_missing`;

-- Refresh optimizer statistics
ANALYZE TABLE `employees`, `vehicles`, `vehicle_services`, `vehicle_maintenance`,
              `vehicle_insurance`, `vehicle_accidents`, `penalties`,
              `expenses`, `cash_transactions`, `driver_assignments`;
