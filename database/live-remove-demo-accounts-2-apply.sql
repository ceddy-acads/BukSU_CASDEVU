-- =====================================================================
-- LIVE SITE, STEP 2 of 2: delete the demo accounts and add admin@gmail.com.
--
-- Run step 1 first: this file reads its tmp_demo_users table and fails
-- without it.
-- Back up first: phpMyAdmin > Export > Quick > SQL.
--
-- Deletes each demo account and what it made: announcements, reservations,
-- borrowings, guest programs. Real accounts stay. Activities are kept and
-- handed to admin@gmail.com: on live the office made real ones while
-- signed in as admin@buksu.edu.ph. So are that account's reservations, and
-- any real loan a demo account released. Audit history stays, with
-- the deleted user shown as nobody. Files on disk are not removed.
--
-- New administrator: admin@gmail.com / admin_casdevu. Change the password
-- on first sign-in.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO users (role_id, email, password_hash, first_name, last_name, status)
SELECT role_id, 'admin@gmail.com',
       '$2y$10$5H6sJHyYiwpJXr.gOz79NOZmVlnccgRTd8xaAk6M07xhLBfh./K76',
       'System', 'Administrator', 'active'
FROM roles WHERE name = 'admin';

START TRANSACTION;

SET @new_admin = (SELECT user_id FROM users WHERE email = 'admin@gmail.com');

DROP TEMPORARY TABLE IF EXISTS demo_ids;
CREATE TEMPORARY TABLE demo_ids (user_id INT UNSIGNED PRIMARY KEY);
INSERT INTO demo_ids
SELECT u.user_id FROM users u JOIN tmp_demo_users t ON t.email = u.email;

-- RESTRICT keys first, so the account delete below can go through.
DELETE b FROM borrowings b JOIN demo_ids d ON d.user_id = b.borrower_id;
UPDATE borrowings b JOIN demo_ids d ON d.user_id = b.released_by SET b.released_by = @new_admin;
UPDATE reservations v JOIN users u ON u.user_id = v.requested_by
SET v.requested_by = @new_admin WHERE u.email = 'admin@buksu.edu.ph';
UPDATE activities a JOIN demo_ids d ON d.user_id = a.created_by SET a.created_by = @new_admin;
DELETE v FROM reservations v JOIN demo_ids d ON d.user_id = v.requested_by;
DELETE i FROM invitations i JOIN demo_ids d ON d.user_id = i.submitted_by;
DELETE n FROM announcements n JOIN demo_ids d ON d.user_id = n.posted_by;

-- Cascades: tokens, notifications, own registrations, coordinator rows, preferences.
DELETE u FROM users u JOIN demo_ids d ON d.user_id = u.user_id;

COMMIT;

DROP TEMPORARY TABLE demo_ids;
DROP TABLE tmp_demo_users;

SELECT r.name AS role, COUNT(*) AS accounts
FROM users u JOIN roles r ON r.role_id = u.role_id
GROUP BY r.name;
