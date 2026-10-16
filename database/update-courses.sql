-- =====================================================================
-- CASMS - Update the course list to BukSU's programs
--
-- For a database created before this list was added. A fresh install from
-- database/schema.sql already has it, so this file is only for existing
-- installs:
--
--   C:\xampp\mysql\bin\mysql.exe -u root casms < database/update-courses.sql
--
-- Adds BukSU's degree programs (as listed by the Office of the Registrar),
-- corrects the names of ones already present, and retires courses that are
-- not on the list. Retired courses are hidden from sign-up and the other
-- course pickers but kept, so students already linked to one keep it.
--
-- Safe to re-run.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO courses (code, name, department) VALUES
    ('ABECON',     'Bachelor of Arts in Economics',                                    'College of Arts and Sciences'),
    ('ABEL',       'Bachelor of Arts in English Language',                             'College of Arts and Sciences'),
    ('ABPHILO-PL', 'Bachelor of Arts in Philosophy Pre-Law',                           'College of Arts and Sciences'),
    ('ABPHILO-TT', 'Bachelor of Arts in Philosophy Teaching Track',                    'College of Arts and Sciences'),
    ('ABSOCIO',    'Bachelor of Arts in Sociology',                                    'College of Arts and Sciences'),
    ('BSBIO',      'Bachelor of Science in Biology Major in Biotechnology',            'College of Arts and Sciences'),
    ('BSCD',       'Bachelor of Science in Community Development',                     'College of Arts and Sciences'),
    ('BSDEVCOM',   'Bachelor of Science in Development Communication',                 'College of Arts and Sciences'),
    ('BSES',       'Bachelor of Science in Environmental Science Major in Environmental Heritage Studies', 'College of Arts and Sciences'),
    ('BSMATH',     'Bachelor of Science in Mathematics',                               'College of Arts and Sciences'),
    ('BSA',        'Bachelor of Science in Accountancy',                               'College of Business'),
    ('BSBA-FM',    'Bachelor of Science in Business Administration Major in Financial Management', 'College of Business'),
    ('BSHM',       'Bachelor of Science in Hospitality Management',                    'College of Business'),
    ('BECED',      'Bachelor of Early Childhood Education',                            'College of Education'),
    ('BEED',       'Bachelor of Elementary Education',                                 'College of Education'),
    ('BPED',       'Bachelor of Physical Education',                                   'College of Education'),
    ('BSED-ENG',   'Bachelor of Secondary Education Major in English',                 'College of Education'),
    ('BSED-FIL',   'Bachelor of Secondary Education Major in Filipino',                'College of Education'),
    ('BSED-MATH',  'Bachelor of Secondary Education Major in Mathematics',             'College of Education'),
    ('BSED-SCI',   'Bachelor of Secondary Education Major in Science',                 'College of Education'),
    ('BSED-SS',    'Bachelor of Secondary Education Major in Social Studies',          'College of Education'),
    ('JD',         'Juris Doctor',                                                     'College of Law'),
    ('BSN',        'Bachelor of Science in Nursing',                                   'College of Nursing'),
    ('BPA',        'Bachelor of Public Administration',                                'College of Public Administration and Governance'),
    ('BSAT',       'Bachelor of Science in Automotive Technology',                     'College of Technologies'),
    ('BSET',       'Bachelor of Science in Electronics Technology',                    'College of Technologies'),
    ('BSEMC-DAT',  'Bachelor of Science in Entertainment and Multimedia Computing Major in Digital Animation Technology', 'College of Technologies'),
    ('BSFT',       'Bachelor of Science in Food Technology',                           'College of Technologies'),
    ('BSIT',       'Bachelor of Science in Information Technology',                    'College of Technologies')
ON DUPLICATE KEY UPDATE
    name       = VALUES(name),
    department = VALUES(department),
    is_active  = 1;

UPDATE courses
   SET is_active = 0
 WHERE code NOT IN ('ABECON', 'ABEL', 'ABPHILO-PL', 'ABPHILO-TT', 'ABSOCIO', 'BSBIO', 'BSCD', 'BSDEVCOM', 'BSES', 'BSMATH', 'BSA', 'BSBA-FM', 'BSHM', 'BECED', 'BEED', 'BPED', 'BSED-ENG', 'BSED-FIL', 'BSED-MATH', 'BSED-SCI', 'BSED-SS', 'JD', 'BSN', 'BPA', 'BSAT', 'BSET', 'BSEMC-DAT', 'BSFT', 'BSIT');
