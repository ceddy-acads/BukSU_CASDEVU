-- =====================================================================
-- CASMS - Demonstration dataset (OPTIONAL)
--
-- Run this AFTER database/schema.sql to fill the system with realistic
-- content for a walkthrough or defence. It is not part of the installer,
-- and the application never requires it.
--
--   C:\xampp\mysql\bin\mysql.exe -u root casms < database/demo-data.sql
--
-- Every account created here uses the password: demo1234
-- The administrator seeded by schema.sql (admin@buksu.edu.ph / admin123)
-- is left untouched.
--
-- Safe to re-run: rows are matched on their unique columns and skipped
-- rather than duplicated.
-- =====================================================================

USE casms;

-- =====================================================================
-- OFFICE PERSONNEL
-- =====================================================================

INSERT IGNORE INTO users (role_id, email, password_hash, first_name, last_name, contact_number, status)
SELECT r.role_id, v.email,
       '$2y$10$wTIZOyWz1J.gZwVQrAwxGeOgemgjbj3wcyPu2tLZuqAzLydhHs/zO',
       v.first_name, v.last_name, v.contact, 'active'
FROM (
    SELECT 'staff'       AS role, 'rosales.office@buksu.edu.ph' AS email,
           'Elena' AS first_name, 'Rosales' AS last_name, '0917-555-0101' AS contact
    UNION ALL SELECT 'staff',       'mendoza.office@buksu.edu.ph',  'Carlo',  'Mendoza',    '0917-555-0102'
    UNION ALL SELECT 'coordinator', 'bautista.coach@buksu.edu.ph',  'Ramon',  'Bautista',   '0917-555-0103'
    UNION ALL SELECT 'coordinator', 'villanueva.arts@buksu.edu.ph', 'Grace',  'Villanueva', '0917-555-0104'
    UNION ALL SELECT 'coordinator', 'delacruz.dance@buksu.edu.ph',  'Miguel', 'Dela Cruz',  '0917-555-0105'
) AS v
JOIN roles r ON r.name = v.role;

-- =====================================================================
-- STUDENTS — thirty across the five seeded courses and four year levels
-- =====================================================================

INSERT IGNORE INTO users
    (role_id, email, password_hash, student_number, first_name, last_name,
     sex, contact_number, course_id, year_level_id, section, status)
SELECT r.role_id,
       LOWER(CONCAT(v.first_name, '.', REPLACE(v.last_name, ' ', ''), '@student.buksu.edu.ph')),
       '$2y$10$wTIZOyWz1J.gZwVQrAwxGeOgemgjbj3wcyPu2tLZuqAzLydhHs/zO',
       v.student_number, v.first_name, v.last_name, v.sex,
       CONCAT('0918-555-', LPAD(v.seq, 4, '0')),
       c.course_id, v.year_level, v.section, 'active'
FROM (
    SELECT 1 AS seq, '2023-10001' AS student_number, 'Andrea' AS first_name, 'Santos' AS last_name,
           'female' AS sex, 'BSIT' AS code, 1 AS year_level, 'A' AS section
    UNION ALL SELECT  2, '2023-10002', 'Bryan',    'Reyes',     'male',   'BSIT', 1, 'A'
    UNION ALL SELECT  3, '2023-10003', 'Camille',  'Lim',       'female', 'BSIT', 2, 'A'
    UNION ALL SELECT  4, '2023-10004', 'Daniel',   'Torres',    'male',   'BSIT', 2, 'B'
    UNION ALL SELECT  5, '2023-10005', 'Erika',    'Navarro',   'female', 'BSIT', 3, 'A'
    UNION ALL SELECT  6, '2023-10006', 'Francis',  'Gomez',     'male',   'BSIT', 3, 'B'
    UNION ALL SELECT  7, '2023-10007', 'Grace',    'Alonzo',    'female', 'BSIT', 4, 'A'
    UNION ALL SELECT  8, '2023-10008', 'Hector',   'Pascual',   'male',   'BSCS', 1, 'A'
    UNION ALL SELECT  9, '2023-10009', 'Irene',    'Bautista',  'female', 'BSCS', 1, 'B'
    UNION ALL SELECT 10, '2023-10010', 'Jerome',   'Castro',    'male',   'BSCS', 2, 'A'
    UNION ALL SELECT 11, '2023-10011', 'Kristine', 'Domingo',   'female', 'BSCS', 2, 'B'
    UNION ALL SELECT 12, '2023-10012', 'Leo',      'Espino',    'male',   'BSCS', 3, 'A'
    UNION ALL SELECT 13, '2023-10013', 'Marites',  'Fajardo',   'female', 'BSCS', 3, 'B'
    UNION ALL SELECT 14, '2023-10014', 'Noel',     'Guevarra',  'male',   'BSCS', 4, 'A'
    UNION ALL SELECT 15, '2023-10015', 'Olivia',   'Hernandez', 'female', 'BEED', 1, 'A'
    UNION ALL SELECT 16, '2023-10016', 'Paulo',    'Ignacio',   'male',   'BEED', 1, 'B'
    UNION ALL SELECT 17, '2023-10017', 'Queenie',  'Javier',    'female', 'BEED', 2, 'A'
    UNION ALL SELECT 18, '2023-10018', 'Rafael',   'Lorenzo',   'male',   'BEED', 2, 'B'
    UNION ALL SELECT 19, '2023-10019', 'Sofia',    'Marquez',   'female', 'BEED', 3, 'A'
    UNION ALL SELECT 20, '2023-10020', 'Tomas',    'Nicolas',   'male',   'BEED', 4, 'A'
    UNION ALL SELECT 21, '2023-10021', 'Ursula',   'Ocampo',    'female', 'BSED', 1, 'A'
    UNION ALL SELECT 22, '2023-10022', 'Victor',   'Padilla',   'male',   'BSED', 2, 'A'
    UNION ALL SELECT 23, '2023-10023', 'Wendy',    'Quimbo',    'female', 'BSED', 2, 'B'
    UNION ALL SELECT 24, '2023-10024', 'Xavier',   'Ramos',     'male',   'BSED', 3, 'A'
    UNION ALL SELECT 25, '2023-10025', 'Yvonne',   'Salazar',   'female', 'BSED', 4, 'A'
    UNION ALL SELECT 26, '2023-10026', 'Zaldy',    'Tolentino', 'male',   'BSN',  1, 'A'
    UNION ALL SELECT 27, '2023-10027', 'Aileen',   'Urbano',    'female', 'BSN',  2, 'A'
    UNION ALL SELECT 28, '2023-10028', 'Benjie',   'Velasco',   'male',   'BSN',  3, 'A'
    UNION ALL SELECT 29, '2023-10029', 'Cristina', 'Ybanez',    'female', 'BSN',  3, 'B'
    UNION ALL SELECT 30, '2023-10030', 'Dennis',   'Zamora',    'male',   'BSN',  4, 'A'
) AS v
JOIN roles r   ON r.name = 'student'
JOIN courses c ON c.code = v.code;

-- Two accounts left pending, so the approval queue is not empty at demo time.
INSERT IGNORE INTO users
    (role_id, email, password_hash, student_number, first_name, last_name,
     course_id, year_level_id, section, status)
SELECT r.role_id, v.email,
       '$2y$10$wTIZOyWz1J.gZwVQrAwxGeOgemgjbj3wcyPu2tLZuqAzLydhHs/zO',
       v.student_number, v.first_name, v.last_name, c.course_id, v.year_level, 'A', 'pending'
FROM (
    SELECT '2023-10031' AS student_number, 'elaine.fernandez@student.buksu.edu.ph' AS email,
           'Elaine' AS first_name, 'Fernandez' AS last_name, 'BSIT' AS code, 1 AS year_level
    UNION ALL SELECT '2023-10032', 'gilbert.aquino@student.buksu.edu.ph', 'Gilbert', 'Aquino', 'BSN', 2
) AS v
JOIN roles r   ON r.name = 'student'
JOIN courses c ON c.code = v.code;

-- =====================================================================
-- ACTIVITIES — dates are relative to today, so the demo never goes stale
-- =====================================================================

INSERT IGNORE INTO activities
    (category_id, venue_id, title, slug, description, eligibility, instructions,
     start_at, end_at, registration_opens_at, registration_closes_at,
     max_participants, status, created_by)
SELECT cat.category_id, ven.venue_id, v.title, v.slug, v.description, v.eligibility, v.instructions,
       DATE_ADD(NOW(), INTERVAL v.start_offset DAY),
       DATE_ADD(NOW(), INTERVAL v.end_offset DAY),
       DATE_ADD(NOW(), INTERVAL v.open_offset DAY),
       DATE_ADD(NOW(), INTERVAL v.close_offset DAY),
       v.max_participants, v.status, admin.user_id
FROM (
    SELECT 'Intramurals' AS category, 'University Gymnasium' AS venue,
           'Intramurals 2026' AS title, 'intramurals-2026' AS slug,
           'Annual inter-college games covering basketball, volleyball, athletics and chess.' AS description,
           'Open to all bona fide students with a valid registration form.' AS eligibility,
           'Register online, then submit your medical clearance and parent consent before the deadline.' AS instructions,
           21 AS start_offset, 24 AS end_offset, -7 AS open_offset, 14 AS close_offset,
           200 AS max_participants, 'upcoming' AS status
    UNION ALL SELECT 'Arts', 'Cultural Center',
           'Sayaw BukSU: Folk Dance Festival', 'sayaw-buksu-folk-dance',
           'Inter-college folk dance competition showcasing regional Philippine dances.',
           'Each college may field one team of eight to twelve dancers.',
           'Team captains register on behalf of their college, then submit the roster.',
           28, 28, -5, 21, 120, 'upcoming'
    UNION ALL SELECT 'Sports', 'Covered Court',
           'Inter-College Basketball Tryouts', 'basketball-tryouts',
           'Tryouts for the university varsity basketball team.',
           'Second year and above, with a medical clearance.',
           'Bring your own kit. Tryouts run for three hours.',
           7, 7, -14, 4, 40, 'upcoming'
    UNION ALL SELECT 'Culture', 'Student Center',
           'Kaamulan Cultural Night', 'kaamulan-cultural-night',
           'An evening showcasing the indigenous culture of Bukidnon through music, dance and storytelling.',
           'Open to all students and staff.',
           'Performers register by group; spectators need no registration.',
           2, 2, -21, 1, 80, 'ongoing'
    UNION ALL SELECT 'Arts', 'Audio Visual Room',
           'Campus Photography Exhibit', 'campus-photography-exhibit',
           'Student photography exhibit on the theme "Everyday BukSU".',
           'Open to all students. Maximum three entries each.',
           'Submit entries as prints at the office before the deadline.',
           -30, -29, -60, -35, 60, 'completed'
    UNION ALL SELECT 'Sports', 'Open Field / Grandstand',
           'Fun Run for Scholarship Fund', 'fun-run-scholarship',
           'Five-kilometre fun run raising funds for the student scholarship programme.',
           'Open to students, staff and alumni.',
           'Assembly at 5:00 AM at the grandstand.',
           -60, -60, -90, -65, 300, 'completed'
) AS v
JOIN activity_categories cat ON cat.name = v.category
JOIN venues ven              ON ven.name = v.venue
JOIN users admin             ON admin.email = 'admin@buksu.edu.ph';

-- =====================================================================
-- COORDINATOR ASSIGNMENTS
-- =====================================================================

INSERT IGNORE INTO activity_coordinators (activity_id, user_id, assignment_role, assigned_by)
SELECT a.activity_id, u.user_id, v.assignment_role, admin.user_id
FROM (
    SELECT 'intramurals-2026'        AS slug, 'bautista.coach@buksu.edu.ph'  AS email, 'Head Coordinator' AS assignment_role
    UNION ALL SELECT 'basketball-tryouts',       'bautista.coach@buksu.edu.ph',  'Trainer'
    UNION ALL SELECT 'sayaw-buksu-folk-dance',   'delacruz.dance@buksu.edu.ph',  'Dance Master'
    UNION ALL SELECT 'kaamulan-cultural-night',  'villanueva.arts@buksu.edu.ph', 'Head Coordinator'
    UNION ALL SELECT 'campus-photography-exhibit','villanueva.arts@buksu.edu.ph','Curator'
) AS v
JOIN activities a ON a.slug   = v.slug
JOIN users u      ON u.email  = v.email
JOIN users admin  ON admin.email = 'admin@buksu.edu.ph';

-- =====================================================================
-- ELIGIBILITY RULES — the tryouts are restricted to 2nd year and above
-- =====================================================================

INSERT INTO activity_eligibility_rules (activity_id, year_level_id)
SELECT a.activity_id, y.year_level_id
FROM activities a
JOIN year_levels y ON y.sort_order IN (2, 3, 4)
WHERE a.slug = 'basketball-tryouts'
  AND NOT EXISTS (
      SELECT 1 FROM activity_eligibility_rules r
       WHERE r.activity_id = a.activity_id AND r.year_level_id = y.year_level_id
  );

-- =====================================================================
-- REQUIREMENTS
-- =====================================================================

-- `activity_requirements` has no unique key, so INSERT IGNORE cannot
-- deduplicate here: the guard is NOT EXISTS on (activity, name).
INSERT INTO activity_requirements
    (activity_id, name, description, is_mandatory, needs_file, deadline_at, sort_order)
SELECT a.activity_id, v.name, v.description, v.is_mandatory, v.needs_file,
       DATE_ADD(NOW(), INTERVAL v.deadline_offset DAY), v.sort_order
FROM (
    SELECT 'intramurals-2026' AS slug, 'Medical Clearance' AS name,
           'Secured from the university clinic within the last six months.' AS description,
           1 AS is_mandatory, 1 AS needs_file, 10 AS deadline_offset, 1 AS sort_order
    UNION ALL SELECT 'intramurals-2026', 'Parent or Guardian Consent',
           'Required for all participants. Download the form at the office.', 1, 1, 10, 2
    UNION ALL SELECT 'intramurals-2026', 'Certificate of Registration',
           'Current semester COR showing enrolled status.', 1, 1, 12, 3
    UNION ALL SELECT 'basketball-tryouts', 'Medical Clearance',
           'Cardiac screening required for contact sports.', 1, 1, 3, 1
    UNION ALL SELECT 'basketball-tryouts', 'Waiver of Liability',
           'Acknowledge the risks of participation.', 1, 0, 3, 2
    UNION ALL SELECT 'sayaw-buksu-folk-dance', 'Team Roster',
           'Complete list of dancers with student numbers.', 1, 1, 18, 1
    UNION ALL SELECT 'sayaw-buksu-folk-dance', 'Music Track',
           'Optional. Submit if using your own accompaniment.', 0, 1, 20, 2
) AS v
JOIN activities a ON a.slug = v.slug
WHERE NOT EXISTS (
    SELECT 1 FROM activity_requirements ex
     WHERE ex.activity_id = a.activity_id AND ex.name = v.name
);

-- =====================================================================
-- REGISTRATIONS — a spread of pending, approved and rejected
-- =====================================================================

-- Intramurals: twelve students, most approved.
INSERT IGNORE INTO registrations (activity_id, user_id, team_name, status, reviewed_by, reviewed_at, attended)
SELECT a.activity_id, u.user_id,
       CONCAT(c.code, ' Team'),
       CASE WHEN u.student_number IN ('2023-10005','2023-10011') THEN 'pending'
            WHEN u.student_number  = '2023-10018'                THEN 'rejected'
            ELSE 'approved' END,
       CASE WHEN u.student_number IN ('2023-10005','2023-10011') THEN NULL
            ELSE staff.user_id END,
       CASE WHEN u.student_number IN ('2023-10005','2023-10011') THEN NULL
            ELSE DATE_SUB(NOW(), INTERVAL 2 DAY) END,
       0
FROM activities a
JOIN users u        ON u.student_number IN ('2023-10001','2023-10002','2023-10004','2023-10005',
                                            '2023-10006','2023-10010','2023-10011','2023-10012',
                                            '2023-10016','2023-10018','2023-10022','2023-10028')
JOIN courses c      ON c.course_id = u.course_id
JOIN users staff    ON staff.email = 'rosales.office@buksu.edu.ph'
WHERE a.slug = 'intramurals-2026';

UPDATE registrations r
JOIN activities a ON a.activity_id = r.activity_id
JOIN users u      ON u.user_id = r.user_id
SET r.review_remarks = 'Medical clearance was not submitted before the deadline.'
WHERE a.slug = 'intramurals-2026' AND u.student_number = '2023-10018';

-- Folk dance: eight approved dancers.
INSERT IGNORE INTO registrations (activity_id, user_id, team_name, status, reviewed_by, reviewed_at, attended)
SELECT a.activity_id, u.user_id, 'College of Education Ensemble', 'approved',
       staff.user_id, DATE_SUB(NOW(), INTERVAL 3 DAY), 0
FROM activities a
JOIN users u     ON u.student_number IN ('2023-10015','2023-10017','2023-10019','2023-10020',
                                         '2023-10021','2023-10023','2023-10024','2023-10025')
JOIN users staff ON staff.email = 'mendoza.office@buksu.edu.ph'
WHERE a.slug = 'sayaw-buksu-folk-dance';

-- Tryouts: five pending, awaiting the coordinator's decision.
INSERT IGNORE INTO registrations (activity_id, user_id, status)
SELECT a.activity_id, u.user_id, 'pending'
FROM activities a
JOIN users u ON u.student_number IN ('2023-10003','2023-10009','2023-10013','2023-10027','2023-10029')
WHERE a.slug = 'basketball-tryouts';

-- Completed activities: attendance already marked.
INSERT IGNORE INTO registrations (activity_id, user_id, status, reviewed_by, reviewed_at, attended)
SELECT a.activity_id, u.user_id, 'completed', staff.user_id,
       DATE_SUB(NOW(), INTERVAL 35 DAY), 1
FROM activities a
JOIN users u     ON u.student_number IN ('2023-10001','2023-10007','2023-10014','2023-10019','2023-10026')
JOIN users staff ON staff.email = 'rosales.office@buksu.edu.ph'
WHERE a.slug = 'campus-photography-exhibit';

INSERT IGNORE INTO registrations (activity_id, user_id, status, reviewed_by, reviewed_at, attended)
SELECT a.activity_id, u.user_id, 'completed', staff.user_id,
       DATE_SUB(NOW(), INTERVAL 65 DAY), 1
FROM activities a
JOIN users u     ON u.student_number IN ('2023-10002','2023-10008','2023-10015','2023-10021',
                                         '2023-10024','2023-10030')
JOIN users staff ON staff.email = 'mendoza.office@buksu.edu.ph'
WHERE a.slug = 'fun-run-scholarship';

-- =====================================================================
-- INVENTORY — twenty items across the four seeded categories
-- =====================================================================

INSERT IGNORE INTO inventory_items
    (inv_category_id, item_code, name, description, size, unit,
     quantity_total, condition_note, storage_location, status)
SELECT ic.inv_category_id, v.item_code, v.name, v.description, v.size, v.unit,
       v.quantity_total, v.condition_note, v.storage_location, v.status
FROM (
    SELECT 'Costume' AS category, 'COS-001' AS item_code, 'Tribal Costume (Female)' AS name,
           'Hand-woven Bukidnon tribal attire for female performers.' AS description,
           'M' AS size, 'set' AS unit, 12 AS quantity_total,
           NULL AS condition_note, 'Costume Room A' AS storage_location, 'available' AS status
    UNION ALL SELECT 'Costume', 'COS-002', 'Tribal Costume (Male)',   'Hand-woven Bukidnon tribal attire for male performers.', 'L',  'set', 12, NULL, 'Costume Room A', 'available'
    UNION ALL SELECT 'Costume', 'COS-003', 'Barong Tagalog',          'Formal barong for ceremonies and presentations.',        'M',  'pc',  20, NULL, 'Costume Room A', 'available'
    UNION ALL SELECT 'Costume', 'COS-004', 'Filipiniana Gown',        'Traditional gown with butterfly sleeves.',               'S',  'pc',  15, NULL, 'Costume Room B', 'available'
    UNION ALL SELECT 'Costume', 'COS-005', 'Maria Clara Ensemble',    'Four-piece traditional ensemble.',                       'M',  'set',  8, NULL, 'Costume Room B', 'available'
    UNION ALL SELECT 'Costume', 'COS-006', 'Dance Sash (assorted)',   'Coloured sashes for folk dance numbers.',                NULL, 'pc',  40, NULL, 'Costume Room B', 'available'
    UNION ALL SELECT 'Costume', 'COS-007', 'Headdress (Tribal)',      'Beaded headdress for cultural presentations.',           NULL, 'pc',  16, 'Three units need bead repair.', 'Costume Room A', 'available'
    UNION ALL SELECT 'Sports Equipment', 'SPT-001', 'Basketball',        'Official size 7 leather basketball.',  NULL, 'pc',  10, NULL, 'Equipment Room', 'available'
    UNION ALL SELECT 'Sports Equipment', 'SPT-002', 'Volleyball',        'Official indoor volleyball.',          NULL, 'pc',   8, NULL, 'Equipment Room', 'available'
    UNION ALL SELECT 'Sports Equipment', 'SPT-003', 'Volleyball Net',    'Competition net with antennae.',       NULL, 'set',  3, NULL, 'Equipment Room', 'available'
    UNION ALL SELECT 'Sports Equipment', 'SPT-004', 'Badminton Racket',  'Aluminium frame racket.',              NULL, 'pc',  16, NULL, 'Equipment Room', 'available'
    UNION ALL SELECT 'Sports Equipment', 'SPT-005', 'Chess Set',         'Tournament chess set with clock.',     NULL, 'set', 12, NULL, 'Equipment Room', 'available'
    UNION ALL SELECT 'Sports Equipment', 'SPT-006', 'Track Starting Block','Adjustable sprint starting block.',  NULL, 'pc',   6, 'One block has a worn footplate.', 'Equipment Room', 'under_maintenance'
    UNION ALL SELECT 'Sports Equipment', 'SPT-007', 'Table Tennis Table', 'Foldable competition table.',         NULL, 'pc',   2, NULL, 'Equipment Room', 'available'
    UNION ALL SELECT 'Props', 'PRP-001', 'Bamboo Poles (Tinikling)', 'Pair of bamboo poles for tinikling.',     NULL, 'pair', 10, NULL, 'Stage Storage', 'available'
    UNION ALL SELECT 'Props', 'PRP-002', 'Woven Mat',                'Banig mat used as a stage prop.',          NULL, 'pc',   8, NULL, 'Stage Storage', 'available'
    UNION ALL SELECT 'Props', 'PRP-003', 'Clay Jar (prop)',          'Lightweight replica jar.',                 NULL, 'pc',   6, 'Two jars chipped after the last festival.', 'Stage Storage', 'damaged'
    UNION ALL SELECT 'Audio Visual', 'AV-001', 'Wireless Microphone',  'Handheld wireless microphone with receiver.', NULL, 'set', 6, NULL, 'AV Room', 'available'
    UNION ALL SELECT 'Audio Visual', 'AV-002', 'Portable Speaker',     'Battery-powered PA speaker.',                 NULL, 'pc',  4, NULL, 'AV Room', 'available'
    UNION ALL SELECT 'Audio Visual', 'AV-003', 'LED Par Light',        'Stage lighting fixture.',                     NULL, 'pc', 12, NULL, 'AV Room', 'available'
) AS v
JOIN inventory_categories ic ON ic.name = v.category;

-- =====================================================================
-- RESERVATIONS AND BORROWINGS
-- =====================================================================

-- Approved reservation for the folk dance festival.
INSERT INTO reservations (activity_id, requested_by, purpose, needed_from, needed_until,
                          status, reviewed_by, reviewed_at)
SELECT a.activity_id, coord.user_id,
       'Costumes and props for the folk dance festival.',
       DATE_ADD(NOW(), INTERVAL 26 DAY), DATE_ADD(NOW(), INTERVAL 29 DAY),
       'approved', staff.user_id, DATE_SUB(NOW(), INTERVAL 1 DAY)
FROM activities a
JOIN users coord ON coord.email = 'delacruz.dance@buksu.edu.ph'
JOIN users staff ON staff.email = 'rosales.office@buksu.edu.ph'
WHERE a.slug = 'sayaw-buksu-folk-dance'
  AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.activity_id = a.activity_id);

INSERT IGNORE INTO reservation_items (reservation_id, item_id, quantity)
SELECT r.reservation_id, i.item_id, v.quantity
FROM (
    SELECT 'COS-001' AS item_code, 8 AS quantity
    UNION ALL SELECT 'COS-002', 8
    UNION ALL SELECT 'COS-006', 16
    UNION ALL SELECT 'PRP-001', 4
) AS v
JOIN inventory_items i ON i.item_code = v.item_code
JOIN reservations r
  ON r.activity_id = (SELECT activity_id FROM activities WHERE slug = 'sayaw-buksu-folk-dance');

-- A pending reservation, so the approval queue has something in it.
INSERT INTO reservations (activity_id, requested_by, purpose, needed_from, needed_until, status)
SELECT a.activity_id, coord.user_id,
       'Sound system and lighting for the tryouts briefing.',
       DATE_ADD(NOW(), INTERVAL 6 DAY), DATE_ADD(NOW(), INTERVAL 8 DAY), 'pending'
FROM activities a
JOIN users coord ON coord.email = 'bautista.coach@buksu.edu.ph'
WHERE a.slug = 'basketball-tryouts'
  AND NOT EXISTS (
      SELECT 1 FROM reservations r
       WHERE r.activity_id = a.activity_id AND r.status = 'pending'
  );

INSERT IGNORE INTO reservation_items (reservation_id, item_id, quantity)
SELECT r.reservation_id, i.item_id, v.quantity
FROM (
    SELECT 'AV-001' AS item_code, 2 AS quantity
    UNION ALL SELECT 'AV-002', 2
) AS v
JOIN inventory_items i ON i.item_code = v.item_code
JOIN reservations r
  ON r.activity_id = (SELECT activity_id FROM activities WHERE slug = 'basketball-tryouts')
 AND r.status = 'pending';

-- Items currently out, including one overdue and one returned damaged.
INSERT INTO borrowings (item_id, borrower_id, quantity, released_by, released_at,
                        expected_return_at, returned_at, received_by, return_condition, return_remarks)
SELECT i.item_id, b.user_id, v.quantity, s.user_id,
       DATE_SUB(NOW(), INTERVAL v.released_days_ago DAY),
       DATE_SUB(NOW(), INTERVAL v.due_days_ago DAY),
       CASE WHEN v.returned_days_ago IS NULL THEN NULL
            ELSE DATE_SUB(NOW(), INTERVAL v.returned_days_ago DAY) END,
       CASE WHEN v.returned_days_ago IS NULL THEN NULL ELSE s.user_id END,
       v.return_condition, v.return_remarks
FROM (
    SELECT 'SPT-001' AS item_code, 'bautista.coach@buksu.edu.ph' AS borrower, 4 AS quantity,
           10 AS released_days_ago, 3 AS due_days_ago,
           CAST(NULL AS UNSIGNED) AS returned_days_ago,
           CAST(NULL AS CHAR(10)) AS return_condition, CAST(NULL AS CHAR(200)) AS return_remarks
    UNION ALL SELECT 'AV-001', 'villanueva.arts@buksu.edu.ph', 2, 4, -3, NULL, NULL, NULL
    UNION ALL SELECT 'COS-003', 'mendoza.office@buksu.edu.ph', 6, 40, 35, 36, 'good', NULL
    UNION ALL SELECT 'PRP-003', 'delacruz.dance@buksu.edu.ph', 2, 70, 65, 64, 'damaged', 'Two jars chipped during transport.'
) AS v
JOIN inventory_items i ON i.item_code = v.item_code
JOIN users b           ON b.email = v.borrower
JOIN users s           ON s.email = 'rosales.office@buksu.edu.ph'
WHERE NOT EXISTS (
    SELECT 1 FROM borrowings bo
     WHERE bo.item_id = i.item_id AND bo.borrower_id = b.user_id
);

-- Item statuses follow what is actually happening to them.
UPDATE inventory_items i
SET i.status = 'borrowed'
WHERE i.item_code IN ('SPT-001', 'AV-001')
  AND i.status = 'available';

UPDATE inventory_items i
SET i.status = 'reserved'
WHERE i.item_code IN ('COS-001', 'COS-002', 'COS-006', 'PRP-001')
  AND i.status = 'available';

-- =====================================================================
-- ANNOUNCEMENTS
-- =====================================================================

-- `announcements` has no unique column, so INSERT IGNORE cannot deduplicate
-- here: each insert is guarded by NOT EXISTS on the title instead.
INSERT INTO announcements
    (activity_id, title, body, is_pinned, status, published_at, posted_by)
SELECT a.activity_id, v.title, v.body, v.is_pinned, 'published',
       DATE_SUB(NOW(), INTERVAL v.days_ago DAY), u.user_id
FROM (
    SELECT 'intramurals-2026' AS slug,
           'Intramurals 2026 registration is now open' AS title,
           'All colleges may now submit their line-ups through the system. Registration closes two weeks before the opening ceremony. Medical clearance and parent consent must be uploaded before the deadline — incomplete entries will not be included in the official roster.' AS body,
           1 AS is_pinned, 3 AS days_ago, 'rosales.office@buksu.edu.ph' AS email
    UNION ALL SELECT 'basketball-tryouts',
           'Basketball tryouts moved to the Covered Court',
           'Because of the ongoing repairs at the gymnasium, this week''s tryouts will be held at the Covered Court instead. The schedule is unchanged. Bring your own kit and a water bottle.',
           0, 1, 'bautista.coach@buksu.edu.ph'
    UNION ALL SELECT 'sayaw-buksu-folk-dance',
           'Folk dance festival: roster deadline reminder',
           'Team captains are reminded to upload the complete roster with student numbers. Teams without a submitted roster three days before the festival will forfeit their slot.',
           0, 2, 'delacruz.dance@buksu.edu.ph'
) AS v
JOIN activities a ON a.slug  = v.slug
JOIN users u      ON u.email = v.email
WHERE NOT EXISTS (
    SELECT 1 FROM announcements ex WHERE ex.title = v.title
);

INSERT INTO announcements (title, body, is_pinned, status, published_at, posted_by)
SELECT 'Office hours during the semestral break',
       'The Office of Culture, Arts, and Sports will be open Monday to Friday, 8:00 AM to 3:00 PM throughout the semestral break. Equipment borrowing requests should be filed at least two days in advance.',
       0, 'published', DATE_SUB(NOW(), INTERVAL 6 DAY), u.user_id
FROM users u
WHERE u.email = 'mendoza.office@buksu.edu.ph'
  AND NOT EXISTS (
      SELECT 1 FROM announcements ex WHERE ex.title = 'Office hours during the semestral break'
  );

INSERT INTO announcements (title, body, is_pinned, status, posted_by)
SELECT 'Draft: Cheerdance competition mechanics',
       'Mechanics are still being finalised with the college representatives. Do not publish yet.',
       0, 'draft', u.user_id
FROM users u
WHERE u.email = 'rosales.office@buksu.edu.ph'
  AND NOT EXISTS (
      SELECT 1 FROM announcements ex WHERE ex.title = 'Draft: Cheerdance competition mechanics'
  );

-- =====================================================================
-- SUMMARY
-- =====================================================================

SELECT 'Demo data loaded.' AS status;
SELECT
    (SELECT COUNT(*) FROM users)            AS users,
    (SELECT COUNT(*) FROM activities)       AS activities,
    (SELECT COUNT(*) FROM registrations)    AS registrations,
    (SELECT COUNT(*) FROM inventory_items)  AS inventory_items,
    (SELECT COUNT(*) FROM reservations)     AS reservations,
    (SELECT COUNT(*) FROM borrowings)       AS borrowings,
    (SELECT COUNT(*) FROM announcements)    AS announcements;
