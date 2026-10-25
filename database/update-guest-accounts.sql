-- =====================================================================
-- Guest accounts (view-only role for people outside the student body)
--
-- Adds the 'guest' role and users.connection_note. Existing installs
-- only need this file; database/schema.sql and demo-data.sql already
-- carry it for a clean install. Safe to run more than once.
--
--   mysql -u root casms < database/update-guest-accounts.sql
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO roles (name, description)
VALUES ('guest', 'Outside the student body: views the schedule and announcements');

-- Guests only: their answer to "How are you connected to the unit's activities?"
-- Guarded through information_schema rather than ADD COLUMN IF NOT EXISTS,
-- which MariaDB accepts and MySQL does not.
SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'connection_note'
);
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE users ADD COLUMN connection_note VARCHAR(500) NULL AFTER section',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- No demo guests here: this script runs on real installs, and an active
-- account with a published password must not reach one. demo-data.sql
-- carries reyes.guest and lim.guest for local testing.
