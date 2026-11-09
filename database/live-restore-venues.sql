-- =====================================================================
-- LIVE SITE: put back the six starter venues from schema.sql.
--
-- live-reset-to-one-admin.sql deleted them by mistake: they are install
-- seed, not demo data. Safe to re-run; `name` is UNIQUE.
-- phpMyAdmin: click the database in the sidebar, then Import this file.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO venues (name, location, capacity) VALUES
    ('University Gymnasium',   'Main Campus',                     2000),
    ('Cultural Center',        'Main Campus',                      500),
    ('Open Field / Grandstand','Main Campus',                     1500),
    ('Student Center',         'Main Campus',                      300),
    ('Audio Visual Room',      'Administration Building',          120),
    ('Covered Court',          'Main Campus',                      800);

SELECT name, location, capacity FROM venues;
