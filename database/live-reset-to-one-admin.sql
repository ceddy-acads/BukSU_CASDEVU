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

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE activities;
TRUNCATE activity_coordinators;
TRUNCATE activity_eligibility_rules;
TRUNCATE activity_requirements;
TRUNCATE announcements;
TRUNCATE audit_logs;
TRUNCATE auth_tokens;
TRUNCATE borrowings;
TRUNCATE highlight_reports;
TRUNCATE inventory_items;
TRUNCATE invitations;
TRUNCATE notifications;
TRUNCATE registrations;
TRUNCATE requirement_submissions;
TRUNCATE reservations;
TRUNCATE reservation_items;
TRUNCATE user_preferences;
TRUNCATE venues;
TRUNCATE users;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO users (role_id, email, password_hash, first_name, last_name, status)
SELECT role_id, 'admin@gmail.com',
       '$2y$10$5H6sJHyYiwpJXr.gOz79NOZmVlnccgRTd8xaAk6M07xhLBfh./K76',
       'System', 'Administrator', 'active'
FROM roles WHERE name = 'admin';

SELECT u.email, r.name AS role, u.status
FROM users u JOIN roles r ON r.role_id = u.role_id;
