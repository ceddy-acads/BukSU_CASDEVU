-- =====================================================================
-- Invitations from outside groups
--
-- Adds invitations: one row per invitation sent through the public form.
-- Existing installs only need this file; database/schema.sql already
-- carries the table for a clean install. Additive only, safe to re-run.
--
--   mysql -u root casms < database/update-outside-invitations.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS invitations (
    invitation_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization    VARCHAR(200) NOT NULL,
    contact_name    VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(40)  NULL,
    title           VARCHAR(200) NOT NULL,
    start_at        DATETIME     NOT NULL,
    end_at          DATETIME     NOT NULL,          -- same as start_at when the sender gave no end
    place           VARCHAR(255) NOT NULL,
    details         TEXT         NOT NULL,
    letter_path     VARCHAR(255) NULL,              -- relative to UPLOAD_PATH, via store_upload()
    letter_name     VARCHAR(255) NULL,
    letter_mime     VARCHAR(100) NULL,
    letter_size     INT UNSIGNED NULL,
    status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    remarks         VARCHAR(500) NULL,              -- rejection reason, sent to the sender
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME     NULL,
    -- The decision email: NULL until a decision; 'logged' = written to mail.log only;
    -- 'contacted' = the office reached them by hand after 'logged' or 'failed'.
    email_status    ENUM('sent','logged','failed','contacted') NULL,
    ip_address      VARCHAR(45)  NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_invitations_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
                                           ON DELETE SET NULL,
    CONSTRAINT chk_invitations_dates   CHECK (end_at >= start_at),
    INDEX idx_invitations_status   (status, start_at),
    INDEX idx_invitations_ip       (ip_address, created_at),
    INDEX idx_invitations_email    (email, created_at)
) ENGINE=InnoDB;
