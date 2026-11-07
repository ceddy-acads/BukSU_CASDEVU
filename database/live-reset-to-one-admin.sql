-- =====================================================================
-- LIVE SITE: delete every account and record, leave one administrator.
--
-- Back up first: phpMyAdmin > Export > Quick > SQL. This cannot be undone.
--
-- Tables are emptied, never dropped: the system keeps working and fills
-- them again as real records come in.
-- Kept: roles, courses, year levels, activity and inventory categories,
-- settings.
-- Deleted: every account except admin@gmail.com, and all venues,
-- inventory items, activities,
-- registrations, requirements, submissions, announcements, reservations,
-- borrowings, highlights, guest programs, notifications and the audit log.
-- Files on disk under storage/uploads are not removed.
--
-- Administrator: admin@gmail.com / admin_casdevu. Change the password on
-- first sign-in.
--
-- phpMyAdmin: click the database in the sidebar, then Import this file.
-- =====================================================================

SET NAMES utf8mb4;

-- Left over from the earlier two-step script, if step 1 was run.
DROP TABLE IF EXISTS tmp_demo_users;

-- DELETE, not TRUNCATE: the live server refuses TRUNCATE on a table a
-- foreign key points at, even with foreign key checks off. Children go
-- first so every delete passes the checks. Safe to re-run.
INSERT IGNORE INTO users (role_id, email, password_hash, first_name, last_name, status)
SELECT role_id, 'admin@gmail.com',
       '$2y$10$5H6sJHyYiwpJXr.gOz79NOZmVlnccgRTd8xaAk6M07xhLBfh./K76',
       'System', 'Administrator', 'active'
FROM roles WHERE name = 'admin';

START TRANSACTION;
DELETE FROM requirement_submissions;
DELETE FROM registrations;
DELETE FROM activity_requirements;
DELETE FROM activity_coordinators;
DELETE FROM activity_eligibility_rules;
DELETE FROM highlight_reports;
DELETE FROM reservation_items;
DELETE FROM borrowings;
DELETE FROM reservations;
DELETE FROM announcements;
DELETE FROM invitations;
DELETE FROM activities;
DELETE FROM inventory_items;
DELETE FROM venues;
DELETE FROM notifications;
DELETE FROM auth_tokens;
DELETE FROM user_preferences;
DELETE FROM audit_logs;
DELETE FROM users WHERE email <> 'admin@gmail.com';
COMMIT;

SELECT u.email, r.name AS role, u.status
FROM users u JOIN roles r ON r.role_id = u.role_id;
