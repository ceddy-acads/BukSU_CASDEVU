-- =====================================================================
-- Personal preferences (settings page)
--
-- Adds user_preferences: one row per person per switch. Existing installs
-- only need this file; database/schema.sql already carries the table for a
-- clean install. There is nothing to backfill: a missing row means the
-- default the reading code names.
--
--   mysql -u root casms < database/update-user-preferences.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id         INT UNSIGNED NOT NULL,
    pref_key        VARCHAR(40)  NOT NULL,
    pref_value      VARCHAR(255) NOT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, pref_key),
    CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users(user_id)
                                     ON DELETE CASCADE
) ENGINE=InnoDB;
