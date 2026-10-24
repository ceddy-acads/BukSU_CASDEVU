-- =====================================================================
-- Clicked notifications leave the bell
--
-- Adds notifications.dismissed_at: set when the person clicks the
-- notification's link in the pop-up under the bell. The row is kept, not
-- deleted, because reminder_already_sent() (includes/reminders.php) reads
-- these rows to avoid sending the same reminder twice. Existing installs
-- only need this file; database/schema.sql already carries the column.
--
--   mysql -u root casms < database/update-notification-dismiss.sql
-- =====================================================================

SET @has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
       AND COLUMN_NAME = 'dismissed_at'
);
SET @ddl := IF(@has_column = 0,
    'ALTER TABLE notifications ADD COLUMN dismissed_at DATETIME NULL AFTER read_at',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
