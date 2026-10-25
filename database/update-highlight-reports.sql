-- =====================================================================
-- Highlight reports
--
-- Adds highlight_reports (one PDF report per activity) and the
-- highlight_max_upload_mb setting. Existing installs only need this file;
-- database/schema.sql already carries both for a clean install. Additive
-- only: safe to run twice.
--
--   mysql -u root casms < database/update-highlight-reports.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS highlight_reports (
    report_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    file_path       VARCHAR(255) NOT NULL,          -- relative to UPLOAD_PATH
    original_name   VARCHAR(255) NOT NULL,
    file_size       INT UNSIGNED NOT NULL,
    uploaded_by     INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_highlight_activity (activity_id),
    CONSTRAINT fk_highlight_activity FOREIGN KEY (activity_id) REFERENCES activities(activity_id)
                                         ON DELETE CASCADE,
    CONSTRAINT fk_highlight_user FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
                                     ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
    ('highlight_max_upload_mb', '40', 'Maximum highlight report PDF size');
