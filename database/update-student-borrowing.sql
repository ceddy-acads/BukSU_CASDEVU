-- Student equipment borrowing: request flag, counter-offer date, per-item
-- opt-out and school ID photos. Safe to run twice (MariaDB ADD COLUMN IF NOT EXISTS).
-- Run: C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-student-borrowing.sql

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS student_request TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS proposed_until  DATETIME NULL;

ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS student_requestable TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS id_front_path VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS id_back_path  VARCHAR(255) NULL;
