-- School ID approval. Non-destructive and safe to rerun.
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id_review_status') = 0, 'ALTER TABLE users ADD COLUMN id_review_status ENUM(''missing'',''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''missing''', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id_reviewed_by') = 0, 'ALTER TABLE users ADD COLUMN id_reviewed_by INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id_reviewed_at') = 0, 'ALTER TABLE users ADD COLUMN id_reviewed_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id_reject_reason') = 0, 'ALTER TABLE users ADD COLUMN id_reject_reason VARCHAR(1000) NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE users SET id_review_status = 'pending' WHERE id_review_status = 'missing' AND NULLIF(TRIM(id_front_path), '') IS NOT NULL AND NULLIF(TRIM(id_back_path), '') IS NOT NULL;
