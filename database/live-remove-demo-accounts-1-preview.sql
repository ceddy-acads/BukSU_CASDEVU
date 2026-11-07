-- =====================================================================
-- LIVE SITE, STEP 1 of 2: list what the demo-account removal deletes.
--
-- Changes no data. It creates the helper table tmp_demo_users, which
-- step 2 reads and then drops.
--
-- phpMyAdmin: click the database in the sidebar, then Import this file.
-- Read the result grid. If a row under "activity" or "announcement"
-- is real office work, stop: step 2 would delete it.
--
-- Demo accounts = every account database/demo-data.sql creates, plus the
-- live check account tmp.live.verify. Accounts not on this list are kept.
-- =====================================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS tmp_demo_users;
-- Copies users.email, so its collation matches whatever this server uses.
CREATE TABLE tmp_demo_users SELECT email FROM users LIMIT 0;
INSERT INTO tmp_demo_users (email) VALUES
    ('admin@buksu.edu.ph'),
    ('aileen.urbano@student.buksu.edu.ph'),
    ('andrea.santos@student.buksu.edu.ph'),
    ('bautista.coach@buksu.edu.ph'),
    ('benjie.velasco@student.buksu.edu.ph'),
    ('bryan.reyes@student.buksu.edu.ph'),
    ('camille.lim@student.buksu.edu.ph'),
    ('cristina.ybanez@student.buksu.edu.ph'),
    ('daniel.torres@student.buksu.edu.ph'),
    ('davepequero_coordinator@buksu.edu.ph'),
    ('delacruz.dance@buksu.edu.ph'),
    ('dennis.zamora@student.buksu.edu.ph'),
    ('elaine.fernandez@student.buksu.edu.ph'),
    ('erika.navarro@student.buksu.edu.ph'),
    ('francis.gomez@student.buksu.edu.ph'),
    ('gilbert.aquino@student.buksu.edu.ph'),
    ('grace.alonzo@student.buksu.edu.ph'),
    ('hector.pascual@student.buksu.edu.ph'),
    ('irene.bautista@student.buksu.edu.ph'),
    ('jerome.castro@student.buksu.edu.ph'),
    ('kristine.domingo@student.buksu.edu.ph'),
    ('leo.espino@student.buksu.edu.ph'),
    ('lim.guest@example.com'),
    ('marites.fajardo@student.buksu.edu.ph'),
    ('mendoza.office@buksu.edu.ph'),
    ('noel.guevarra@student.buksu.edu.ph'),
    ('olivia.hernandez@student.buksu.edu.ph'),
    ('paulo.ignacio@student.buksu.edu.ph'),
    ('queenie.javier@student.buksu.edu.ph'),
    ('rafael.lorenzo@student.buksu.edu.ph'),
    ('reyes.guest@example.com'),
    ('rhysacaruz_staff@buksu.edu.ph'),
    ('rosales.office@buksu.edu.ph'),
    ('sofia.marquez@student.buksu.edu.ph'),
    ('tmp.live.verify@buksu.edu.ph'),
    ('tomas.nicolas@student.buksu.edu.ph'),
    ('ursula.ocampo@student.buksu.edu.ph'),
    ('victor.padilla@student.buksu.edu.ph'),
    ('villanueva.arts@buksu.edu.ph'),
    ('wendy.quimbo@student.buksu.edu.ph'),
    ('xavier.ramos@student.buksu.edu.ph'),
    ('xyrhasacal_admin@buksu.edu.ph'),
    ('yvonne.salazar@student.buksu.edu.ph'),
    ('zaldy.tolentino@student.buksu.edu.ph')
;

SELECT 'account' AS kind, u.email AS item, r.name AS detail
FROM users u JOIN roles r ON r.role_id = u.role_id JOIN tmp_demo_users t ON t.email = u.email
UNION ALL
SELECT 'activity', a.title, CONCAT('created ', a.created_at, ' by ', t.email)
FROM activities a JOIN users u ON u.user_id = a.created_by JOIN tmp_demo_users t ON t.email = u.email
UNION ALL
SELECT 'announcement', n.title, CONCAT('posted ', n.created_at, ' by ', t.email)
FROM announcements n JOIN users u ON u.user_id = n.posted_by JOIN tmp_demo_users t ON t.email = u.email
UNION ALL
SELECT 'real registration lost', CONCAT(ru.email, ' in ', a.title), 'activity was made by a demo account'
FROM registrations g
JOIN users ru ON ru.user_id = g.user_id
JOIN activities a ON a.activity_id = g.activity_id
JOIN users au ON au.user_id = a.created_by
JOIN tmp_demo_users t ON t.email = au.email
WHERE ru.email NOT IN (SELECT email FROM tmp_demo_users)
UNION ALL
SELECT 'kept account', u.email, r.name
FROM users u JOIN roles r ON r.role_id = u.role_id
WHERE u.email NOT IN (SELECT email FROM tmp_demo_users);
