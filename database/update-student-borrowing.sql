-- Student equipment borrowing: request flag, counter-offer date, per-item
-- opt-out and school ID photos. Safe to run twice, on MariaDB and MySQL:
-- each column is added only when information_schema says it is missing
-- (ADD COLUMN IF NOT EXISTS is MariaDB-only).
-- Run: C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-student-borrowing.sql

SET @t := 'reservations', @c := 'student_request',
    @def := 'TINYINT(1) NOT NULL DEFAULT 0';
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c) = 0,
               CONCAT('ALTER TABLE ', @t, ' ADD COLUMN ', @c, ' ', @def), 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'reservations', @c := 'proposed_until',
    @def := 'DATETIME NULL';
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c) = 0,
               CONCAT('ALTER TABLE ', @t, ' ADD COLUMN ', @c, ' ', @def), 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'inventory_items', @c := 'student_requestable',
    @def := 'TINYINT(1) NOT NULL DEFAULT 1';
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c) = 0,
               CONCAT('ALTER TABLE ', @t, ' ADD COLUMN ', @c, ' ', @def), 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'users', @c := 'id_front_path',
    @def := 'VARCHAR(255) NULL';
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c) = 0,
               CONCAT('ALTER TABLE ', @t, ' ADD COLUMN ', @c, ' ', @def), 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t := 'users', @c := 'id_back_path',
    @def := 'VARCHAR(255) NULL';
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = @c) = 0,
               CONCAT('ALTER TABLE ', @t, ' ADD COLUMN ', @c, ' ', @def), 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
