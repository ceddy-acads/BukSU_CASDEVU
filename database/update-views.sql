-- =====================================================================
-- CASMS - Update database views
--
-- For a database created before these view fixes. A fresh install from
-- database/schema.sql already has them, so this is only for existing
-- installs:
--
--   C:\xampp\mysql\bin\mysql.exe -u root casms < database/update-views.sql
--
-- v_missing_requirements: no longer counts documents for rejected or
-- withdrawn registrations or cancelled activities, which nobody can upload
-- to any more (the student dashboard showed them as missing forever).
--
-- Safe to re-run. Changes no data.
-- =====================================================================

SET NAMES utf8mb4;

CREATE OR REPLACE VIEW v_missing_requirements AS
SELECT
    r.registration_id,
    r.user_id,
    u.student_number,
    CONCAT(u.last_name, ', ', u.first_name) AS student_name,
    a.activity_id,
    a.title AS activity_title,
    ar.requirement_id,
    ar.name AS requirement_name,
    ar.deadline_at,
    COALESCE(rs.status, 'not_submitted') AS submission_status
FROM registrations r
JOIN users u                  ON u.user_id = r.user_id
JOIN activities a             ON a.activity_id = r.activity_id
JOIN activity_requirements ar ON ar.activity_id = a.activity_id
LEFT JOIN requirement_submissions rs
       ON rs.requirement_id = ar.requirement_id
      AND rs.registration_id = r.registration_id
WHERE ar.is_mandatory = 1
  AND r.status IN ('pending', 'approved')
  AND a.status <> 'cancelled'
  AND (rs.submission_id IS NULL OR rs.status = 'rejected');
