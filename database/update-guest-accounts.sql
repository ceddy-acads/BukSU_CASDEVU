-- =====================================================================
-- Guest accounts (view-only role for people outside the student body)
--
-- Adds the 'guest' role, users.connection_note, and two demo guests
-- (password demo1234). Existing installs only need this file;
-- database/schema.sql and demo-data.sql already carry it for a clean
-- install. Safe to run more than once.
--
--   mysql -u root casms < database/update-guest-accounts.sql
-- =====================================================================

SET NAMES utf8mb4;
USE casms;

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

INSERT IGNORE INTO users (role_id, email, password_hash, first_name, last_name, contact_number, connection_note, status)
SELECT role_id, 'reyes.guest@example.com',
       '$2y$10$wTIZOyWz1J.gZwVQrAwxGeOgemgjbj3wcyPu2tLZuqAzLydhHs/zO',
       'Maricel', 'Reyes', '0917-555-0201',
       'Parent of Andrea Santos, who dances with the university troupe.', 'active'
FROM roles WHERE name = 'guest';

INSERT IGNORE INTO users (role_id, email, password_hash, first_name, last_name, contact_number, connection_note, status)
SELECT role_id, 'lim.guest@example.com',
       '$2y$10$wTIZOyWz1J.gZwVQrAwxGeOgemgjbj3wcyPu2tLZuqAzLydhHs/zO',
       'Joel', 'Lim', '0917-555-0202',
       'Volunteer coach of the Malaybalay youth chess club; we join the open tournaments.', 'pending'
FROM roles WHERE name = 'guest';
