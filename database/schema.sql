-- =====================================================================
-- CASMS - Culture, Arts, and Sports Management System
-- Bukidnon State University - Office of Culture, Arts, and Sports
--
-- Target: MySQL 5.7+ / MariaDB 10.4+ (XAMPP default)
-- Engine: InnoDB (required for foreign keys)
-- Charset: utf8mb4 (supports full Unicode incl. emoji in announcements)
--
-- Tables are grouped by module and created in dependency order.
-- =====================================================================

DROP DATABASE IF EXISTS casms;
CREATE DATABASE casms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE casms;


-- =====================================================================
-- MODULE 1: USER ACCOUNT AND ACCESS
-- =====================================================================

-- Academic reference tables. Kept separate from `users` so that the office
-- can rename a course or add a year level without touching student records.

CREATE TABLE courses (
    course_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL UNIQUE,   -- e.g. 'BSIT'
    name            VARCHAR(150) NOT NULL,          -- e.g. 'BS Information Technology'
    department      VARCHAR(150) NULL,              -- e.g. 'College of Technologies'
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE year_levels (
    year_level_id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(30) NOT NULL UNIQUE,    -- '1st Year' ... '4th Year'
    sort_order      TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Roles are a lookup table rather than an ENUM so an administrator can add
-- a role (e.g. 'Trainer') later without an ALTER TABLE.

CREATE TABLE roles (
    role_id         TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(40)  NOT NULL UNIQUE,   -- student | staff | coordinator | admin
    description     VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id         TINYINT UNSIGNED NOT NULL,

    -- Credentials
    email           VARCHAR(150) NOT NULL UNIQUE,   -- BukSU institutional email
    password_hash   VARCHAR(255) NOT NULL,          -- password_hash(), PASSWORD_DEFAULT
    -- Identity
    student_number  VARCHAR(30)  NULL UNIQUE,       -- NULL for staff/admin accounts
    first_name      VARCHAR(80)  NOT NULL,
    middle_name     VARCHAR(80)  NULL,
    last_name       VARCHAR(80)  NOT NULL,
    sex             ENUM('male','female','other') NULL,
    birth_date      DATE         NULL,
    contact_number  VARCHAR(30)  NULL,
    photo_path      VARCHAR(255) NULL,

    -- Academic placement (students only; NULL for office personnel)
    course_id       INT UNSIGNED     NULL,
    year_level_id   TINYINT UNSIGNED NULL,
    section         VARCHAR(20)      NULL,

    -- Account state
    status          ENUM('pending','active','inactive','suspended')
                        NOT NULL DEFAULT 'pending',
    last_login_at   DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_role       FOREIGN KEY (role_id)       REFERENCES roles(role_id),
    CONSTRAINT fk_users_course     FOREIGN KEY (course_id)     REFERENCES courses(course_id)
                                       ON DELETE SET NULL,
    CONSTRAINT fk_users_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels(year_level_id)
                                       ON DELETE SET NULL,

    INDEX idx_users_role   (role_id),
    INDEX idx_users_status (status),
    INDEX idx_users_name   (last_name, first_name)
) ENGINE=InnoDB;

-- Remember-me tokens and password resets. Storing only a hash of the token
-- means a leaked database row cannot be replayed as a login.

CREATE TABLE auth_tokens (
    token_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    type            ENUM('remember_me','password_reset','email_verify') NOT NULL,
    token_hash      CHAR(64)  NOT NULL,             -- hash('sha256', $rawToken)
    expires_at      DATETIME  NOT NULL,
    used_at         DATETIME  NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(user_id)
                                       ON DELETE CASCADE,
    UNIQUE KEY uq_auth_token (token_hash),
    INDEX idx_auth_tokens_user (user_id, type)
) ENGINE=InnoDB;


-- =====================================================================
-- MODULE 2: ACTIVITY AND EVENT MANAGEMENT
-- =====================================================================

CREATE TABLE activity_categories (
    category_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL UNIQUE,   -- Culture | Arts | Sports | Intramurals
    description     VARCHAR(255) NULL,
    color_hex       CHAR(7)      NULL,              -- calendar colour, e.g. '#B33A3A'
    is_active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE venues (
    venue_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL UNIQUE,   -- 'BukSU Gymnasium'
    location        VARCHAR(255) NULL,
    capacity        INT UNSIGNED NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE activities (
    activity_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     INT UNSIGNED NOT NULL,
    venue_id        INT UNSIGNED NULL,              -- NULL until venue is confirmed

    title           VARCHAR(200) NOT NULL,
    slug            VARCHAR(220) NOT NULL UNIQUE,   -- clean URL: /activity/intrams-2026
    description     TEXT         NULL,
    eligibility     TEXT         NULL,              -- free text: who may join
    instructions    TEXT         NULL,              -- how to participate
    banner_path     VARCHAR(255) NULL,

    -- Schedule. start_at/end_at drive both the calendar and conflict checking.
    start_at        DATETIME NOT NULL,
    end_at          DATETIME NOT NULL,
    registration_opens_at  DATETIME NULL,
    registration_closes_at DATETIME NULL,

    max_participants INT UNSIGNED NULL,             -- NULL = unlimited

    status          ENUM('draft','upcoming','ongoing','completed','cancelled','closed')
                        NOT NULL DEFAULT 'draft',

    created_by      INT UNSIGNED NOT NULL,          -- staff/admin who authored it
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_activities_category FOREIGN KEY (category_id) REFERENCES activity_categories(category_id),
    CONSTRAINT fk_activities_venue    FOREIGN KEY (venue_id)    REFERENCES venues(venue_id)
                                          ON DELETE SET NULL,
    CONSTRAINT fk_activities_author   FOREIGN KEY (created_by)  REFERENCES users(user_id),
    CONSTRAINT chk_activities_dates   CHECK (end_at >= start_at),

    INDEX idx_activities_status   (status),
    INDEX idx_activities_schedule (start_at, end_at),
    INDEX idx_activities_venue    (venue_id, start_at, end_at)
) ENGINE=InnoDB;

-- Which year levels / courses an activity is open to. An activity with NO rows
-- here is open to everyone; rows act as a whitelist.

CREATE TABLE activity_eligibility_rules (
    rule_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NOT NULL,
    year_level_id   TINYINT UNSIGNED NULL,
    course_id       INT UNSIGNED     NULL,

    CONSTRAINT fk_aer_activity   FOREIGN KEY (activity_id)   REFERENCES activities(activity_id)
                                     ON DELETE CASCADE,
    CONSTRAINT fk_aer_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels(year_level_id)
                                     ON DELETE CASCADE,
    CONSTRAINT fk_aer_course     FOREIGN KEY (course_id)     REFERENCES courses(course_id)
                                     ON DELETE CASCADE,
    INDEX idx_aer_activity (activity_id)
) ENGINE=InnoDB;

-- Staff/coordinators assigned to run an activity. Many-to-many: one activity
-- may have several coordinators, one coordinator handles several activities.

CREATE TABLE activity_coordinators (
    activity_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    assignment_role VARCHAR(80) NULL,               -- 'Head Coordinator', 'Trainer'
    assigned_by     INT UNSIGNED NULL,
    assigned_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (activity_id, user_id),
    CONSTRAINT fk_ac_activity FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
                                  ON DELETE CASCADE,
    CONSTRAINT fk_ac_user     FOREIGN KEY (user_id)     REFERENCES users(user_id)
                                  ON DELETE CASCADE,
    CONSTRAINT fk_ac_assigner FOREIGN KEY (assigned_by) REFERENCES users(user_id)
                                  ON DELETE SET NULL
) ENGINE=InnoDB;


-- =====================================================================
-- MODULE 3: ANNOUNCEMENTS AND DISSEMINATION
-- =====================================================================

CREATE TABLE announcements (
    announcement_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NULL,              -- optional link to an activity
    title           VARCHAR(200) NOT NULL,
    body            TEXT         NOT NULL,
    attachment_path VARCHAR(255) NULL,
    is_pinned       TINYINT(1)   NOT NULL DEFAULT 0,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at    DATETIME     NULL,
    posted_by       INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_ann_activity FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
                                   ON DELETE SET NULL,
    CONSTRAINT fk_ann_author   FOREIGN KEY (posted_by)   REFERENCES users(user_id),
    INDEX idx_ann_published (status, published_at),
    FULLTEXT KEY ft_ann_search (title, body)        -- powers keyword search
) ENGINE=InnoDB;

-- One row per user per notification. `link_url` lets the bell icon deep-link
-- straight to the activity, requirement, or reservation that changed.

CREATE TABLE notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    type            ENUM('activity','announcement','registration','requirement',
                         'reservation','deadline','system') NOT NULL,
    title           VARCHAR(200) NOT NULL,
    message         VARCHAR(500) NOT NULL,
    link_url        VARCHAR(255) NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    read_at         DATETIME     NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id)
                                 ON DELETE CASCADE,
    INDEX idx_notif_inbox (user_id, is_read, created_at)
) ENGINE=InnoDB;


-- =====================================================================
-- MODULE 4: PARTICIPATION MANAGEMENT
-- =====================================================================

-- The join between a student and an activity. The UNIQUE key is what stops a
-- student from registering for the same activity twice.

CREATE TABLE registrations (
    registration_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,

    team_name       VARCHAR(120) NULL,              -- for team-based sports
    remarks         VARCHAR(500) NULL,              -- student's note on applying

    status          ENUM('pending','approved','rejected','withdrawn','completed')
                        NOT NULL DEFAULT 'pending',
    reviewed_by     INT UNSIGNED NULL,              -- staff/coordinator who decided
    reviewed_at     DATETIME     NULL,
    review_remarks  VARCHAR(500) NULL,              -- reason for rejection

    attended        TINYINT(1)   NOT NULL DEFAULT 0,
    registered_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_reg_activity FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
                                   ON DELETE CASCADE,
    CONSTRAINT fk_reg_user     FOREIGN KEY (user_id)     REFERENCES users(user_id)
                                   ON DELETE CASCADE,
    CONSTRAINT fk_reg_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
                                   ON DELETE SET NULL,

    UNIQUE KEY uq_reg_once (activity_id, user_id),
    INDEX idx_reg_status   (activity_id, status),
    INDEX idx_reg_student  (user_id, status)
) ENGINE=InnoDB;


-- =====================================================================
-- MODULE 5: REQUIREMENTS MANAGEMENT
-- =====================================================================

-- What an activity asks for (defined once by staff)...
CREATE TABLE activity_requirements (
    requirement_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,          -- 'Medical Clearance'
    description     VARCHAR(500) NULL,
    is_mandatory    TINYINT(1)   NOT NULL DEFAULT 1,
    needs_file      TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = acknowledgement only
    deadline_at     DATETIME     NULL,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_req_activity FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
                                   ON DELETE CASCADE,
    INDEX idx_req_activity (activity_id, sort_order)
) ENGINE=InnoDB;

-- ...and what each student actually turned in.
-- A student's outstanding requirements = activity_requirements LEFT JOIN this
-- table WHERE submission_id IS NULL OR status = 'rejected'.

CREATE TABLE requirement_submissions (
    submission_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requirement_id  INT UNSIGNED NOT NULL,
    registration_id INT UNSIGNED NOT NULL,          -- ties submission to the right activity

    file_path       VARCHAR(255) NULL,              -- uploads/requirements/<hash>.pdf
    original_name   VARCHAR(255) NULL,
    mime_type       VARCHAR(100) NULL,
    file_size       INT UNSIGNED NULL,
    note            VARCHAR(500) NULL,

    status          ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by     INT UNSIGNED NULL,
    verified_at     DATETIME     NULL,
    reject_reason   VARCHAR(500) NULL,

    submitted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_sub_requirement  FOREIGN KEY (requirement_id)  REFERENCES activity_requirements(requirement_id)
                                       ON DELETE CASCADE,
    CONSTRAINT fk_sub_registration FOREIGN KEY (registration_id) REFERENCES registrations(registration_id)
                                       ON DELETE CASCADE,
    CONSTRAINT fk_sub_verifier     FOREIGN KEY (verified_by)     REFERENCES users(user_id)
                                       ON DELETE SET NULL,

    UNIQUE KEY uq_sub_once (requirement_id, registration_id),
    INDEX idx_sub_status (status)
) ENGINE=InnoDB;


-- =====================================================================
-- MODULE 6: COSTUME AND EQUIPMENT MANAGEMENT
-- =====================================================================

CREATE TABLE inventory_categories (
    inv_category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80) NOT NULL UNIQUE,    -- 'Costume', 'Sports Equipment'
    description     VARCHAR(255) NULL
) ENGINE=InnoDB;

-- Quantity model: `quantity_total` is what the office owns. Quantity currently
-- out on loan is DERIVED by summing open borrowings, never stored, so the two
-- numbers can never drift apart.

CREATE TABLE inventory_items (
    item_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inv_category_id INT UNSIGNED NOT NULL,
    item_code       VARCHAR(40)  NOT NULL UNIQUE,   -- 'COS-001'
    name            VARCHAR(150) NOT NULL,
    description     TEXT         NULL,
    size            VARCHAR(40)  NULL,              -- costumes: 'M', 'L'
    unit            VARCHAR(30)  NOT NULL DEFAULT 'pc',
    quantity_total  INT UNSIGNED NOT NULL DEFAULT 1,
    condition_note  VARCHAR(255) NULL,
    photo_path      VARCHAR(255) NULL,
    storage_location VARCHAR(150) NULL,

    status          ENUM('available','reserved','borrowed','damaged',
                         'under_maintenance','unavailable')
                        NOT NULL DEFAULT 'available',

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_item_category FOREIGN KEY (inv_category_id) REFERENCES inventory_categories(inv_category_id),
    INDEX idx_item_status (status),
    INDEX idx_item_name   (name)
) ENGINE=InnoDB;

-- A request to hold items for a date range. Approving a reservation is what
-- later allows the borrowing record to be issued.

CREATE TABLE reservations (
    reservation_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NULL,              -- what it's needed for
    requested_by    INT UNSIGNED NOT NULL,
    purpose         VARCHAR(500) NULL,

    needed_from     DATETIME NOT NULL,
    needed_until    DATETIME NOT NULL,

    status          ENUM('pending','approved','rejected','cancelled','fulfilled')
                        NOT NULL DEFAULT 'pending',
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME     NULL,
    review_remarks  VARCHAR(500) NULL,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_resv_activity  FOREIGN KEY (activity_id)  REFERENCES activities(activity_id)
                                     ON DELETE SET NULL,
    CONSTRAINT fk_resv_requester FOREIGN KEY (requested_by) REFERENCES users(user_id),
    CONSTRAINT fk_resv_reviewer  FOREIGN KEY (reviewed_by)  REFERENCES users(user_id)
                                     ON DELETE SET NULL,
    CONSTRAINT chk_resv_dates    CHECK (needed_until >= needed_from),
    INDEX idx_resv_status (status),
    INDEX idx_resv_window (needed_from, needed_until)
) ENGINE=InnoDB;

CREATE TABLE reservation_items (
    reservation_id  INT UNSIGNED NOT NULL,
    item_id         INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (reservation_id, item_id),
    CONSTRAINT fk_ri_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id)
                                     ON DELETE CASCADE,
    CONSTRAINT fk_ri_item        FOREIGN KEY (item_id)        REFERENCES inventory_items(item_id)
                                     ON DELETE CASCADE
) ENGINE=InnoDB;

-- The physical hand-over log. One row per item actually released.
-- Open loans = WHERE returned_at IS NULL.

CREATE TABLE borrowings (
    borrowing_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id  INT UNSIGNED NULL,              -- NULL for walk-in borrowing
    item_id         INT UNSIGNED NOT NULL,
    borrower_id     INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,

    released_by     INT UNSIGNED NOT NULL,          -- staff who handed it out
    released_at     DATETIME NOT NULL,
    expected_return_at DATETIME NOT NULL,

    returned_at     DATETIME     NULL,
    received_by     INT UNSIGNED NULL,              -- staff who accepted the return
    return_condition ENUM('good','damaged','lost') NULL,
    return_remarks  VARCHAR(500) NULL,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_brw_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id)
                                      ON DELETE SET NULL,
    CONSTRAINT fk_brw_item        FOREIGN KEY (item_id)        REFERENCES inventory_items(item_id),
    CONSTRAINT fk_brw_borrower    FOREIGN KEY (borrower_id)    REFERENCES users(user_id),
    CONSTRAINT fk_brw_releaser    FOREIGN KEY (released_by)    REFERENCES users(user_id),
    CONSTRAINT fk_brw_receiver    FOREIGN KEY (received_by)    REFERENCES users(user_id)
                                      ON DELETE SET NULL,

    INDEX idx_brw_open     (item_id, returned_at),
    INDEX idx_brw_borrower (borrower_id),
    INDEX idx_brw_overdue  (returned_at, expected_return_at)
) ENGINE=InnoDB;


-- =====================================================================
-- MODULE 9: ADMINISTRATION AND SECURITY
-- =====================================================================

-- Generic audit log. `entity_type` + `entity_id` point at any row in any table,
-- so one table covers activities, inventory, requirements, and users alike.

CREATE TABLE audit_logs (
    log_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,              -- NULL = system/cron action
    action          VARCHAR(60)  NOT NULL,          -- 'create','update','delete','approve','login'
    entity_type     VARCHAR(60)  NOT NULL,          -- 'activity','inventory_item','user'
    entity_id       INT UNSIGNED NULL,
    description     VARCHAR(500) NULL,
    old_values      JSON NULL,                      -- MariaDB stores JSON as LONGTEXT; fine
    new_values      JSON NULL,
    ip_address      VARCHAR(45)  NULL,              -- IPv6-safe length
    user_agent      VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id)
                                 ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_when   (created_at)
) ENGINE=InnoDB;

-- Key/value settings so the office can change deadlines-reminder lead time,
-- office name on printed reports, etc., without editing PHP.

CREATE TABLE settings (
    setting_key     VARCHAR(80) PRIMARY KEY,
    setting_value   TEXT NULL,
    description     VARCHAR(255) NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- =====================================================================
-- VIEWS - back the reporting screens so PHP does not rebuild these joins
-- =====================================================================

-- Live availability: total owned minus what is currently out on loan.
CREATE OR REPLACE VIEW v_item_availability AS
SELECT
    i.item_id,
    i.item_code,
    i.name,
    ic.name AS category,
    i.status,
    i.quantity_total,
    COALESCE(SUM(b.quantity), 0) AS quantity_out,
    i.quantity_total - COALESCE(SUM(b.quantity), 0) AS quantity_available
FROM inventory_items i
JOIN inventory_categories ic ON ic.inv_category_id = i.inv_category_id
LEFT JOIN borrowings b
       ON b.item_id = i.item_id
      AND b.returned_at IS NULL
GROUP BY i.item_id, i.item_code, i.name, ic.name, i.status, i.quantity_total;

-- Participation report source: one row per activity with approval counts.
CREATE OR REPLACE VIEW v_activity_participation AS
SELECT
    a.activity_id,
    a.title,
    ac.name AS category,
    a.status,
    a.start_at,
    COUNT(r.registration_id)                                        AS total_registered,
    SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END)          AS total_approved,
    SUM(CASE WHEN r.status = 'pending'  THEN 1 ELSE 0 END)          AS total_pending,
    SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END)          AS total_rejected,
    SUM(CASE WHEN r.attended = 1        THEN 1 ELSE 0 END)          AS total_attended
FROM activities a
JOIN activity_categories ac ON ac.category_id = a.category_id
LEFT JOIN registrations r   ON r.activity_id = a.activity_id
GROUP BY a.activity_id, a.title, ac.name, a.status, a.start_at;

-- Outstanding requirements per student per activity - drives both the student's
-- "missing requirements" panel and the staff follow-up list.
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
  AND (rs.submission_id IS NULL OR rs.status = 'rejected');


-- =====================================================================
-- SEED DATA - minimum rows needed for the system to run
-- =====================================================================

INSERT INTO roles (name, description) VALUES
    ('admin',       'Full system access including user and category management'),
    ('staff',       'Office personnel: activities, requirements, inventory, reports'),
    ('coordinator', 'Assigned to specific activities: participants and scheduling'),
    ('student',     'Views activities, registers, submits requirements');

INSERT INTO year_levels (label, sort_order) VALUES
    ('1st Year', 1), ('2nd Year', 2), ('3rd Year', 3), ('4th Year', 4);

INSERT INTO activity_categories (name, description, color_hex) VALUES
    ('Culture',     'Cultural programs, festivals, and heritage activities', '#8E44AD'),
    ('Arts',        'Visual arts, music, dance, theater, and literary events', '#E67E22'),
    ('Sports',      'Athletic competitions and training',                     '#27AE60'),
    ('Intramurals', 'Inter-college and inter-department games',               '#2980B9'),
    ('Others',      'Other school activities managed by the office',          '#7F8C8D');

INSERT INTO inventory_categories (name, description) VALUES
    ('Costume',          'Performance costumes, uniforms, and accessories'),
    ('Sports Equipment', 'Balls, nets, rackets, and athletic gear'),
    ('Props',            'Stage props and set pieces'),
    ('Audio Visual',     'Sound systems, microphones, and lighting');

INSERT INTO courses (code, name, department) VALUES
    ('BSIT',  'BS Information Technology',      'College of Technologies'),
    ('BSCS',  'BS Computer Science',            'College of Technologies'),
    ('BEED',  'Bachelor of Elementary Education','College of Education'),
    ('BSED',  'Bachelor of Secondary Education','College of Education'),
    ('BSN',   'BS Nursing',                     'College of Nursing');

INSERT INTO settings (setting_key, setting_value, description) VALUES
    ('office_name',            'Office of Culture, Arts, and Sports', 'Printed on reports'),
    ('university_name',        'Bukidnon State University',           'Printed on reports'),
    ('reminder_lead_days',     '3',    'Days before a deadline to send reminders'),
    ('max_upload_size_mb',     '5',    'Maximum requirement file size'),
    ('allowed_upload_types',   'pdf,jpg,jpeg,png', 'Accepted requirement file extensions');

-- Default administrator. Password is 'admin123' - CHANGE ON FIRST LOGIN.
INSERT INTO users (role_id, email, password_hash, first_name, last_name, status)
SELECT role_id,
       'admin@buksu.edu.ph',
       '$2y$10$wNrwyUgLASdwlHrAOhs6Juk2wyCqeAPaj2qz2dyuYP8./26AApV5O',
       'System', 'Administrator', 'active'
FROM roles WHERE name = 'admin';
