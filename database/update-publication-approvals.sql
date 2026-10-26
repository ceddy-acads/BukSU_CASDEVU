-- Back up first. Apply once to an existing CASMS database; preserves existing content.
ALTER TABLE activities
    ADD approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    ADD review_version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD reviewed_by INT UNSIGNED NULL,
    ADD reviewed_at DATETIME NULL,
    ADD rejection_reason VARCHAR(500) NULL,
    ADD CONSTRAINT fk_activity_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD INDEX idx_activity_approval (approval_status, created_at);

ALTER TABLE announcements
    MODIFY status ENUM('draft','pending','published','rejected','archived') NOT NULL DEFAULT 'draft',
    ADD requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    ADD review_version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD reviewed_by INT UNSIGNED NULL,
    ADD reviewed_at DATETIME NULL,
    ADD rejection_reason VARCHAR(500) NULL,
    ADD CONSTRAINT fk_announcement_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE announcements a JOIN users u ON u.user_id = a.posted_by
    JOIN roles r ON r.role_id = u.role_id
    SET a.requires_approval = 1 WHERE r.name = 'coordinator';

ALTER TABLE invitations
    ADD submitted_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_invitation_submitter FOREIGN KEY (submitted_by) REFERENCES users(user_id),
    ADD INDEX idx_invitation_submitter (submitted_by, status, start_at);
