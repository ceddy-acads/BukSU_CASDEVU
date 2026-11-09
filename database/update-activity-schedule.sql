-- =====================================================================
-- Activities: status follows the dates (FR-2.5), the end date and
-- registration become optional.
--
-- Run ONCE (the ALTER fails if the column already exists).
-- phpMyAdmin: click the database in the sidebar, then Import this file.
-- =====================================================================

-- NULL end = no set end: the activity stays ongoing until someone closes it.
-- requires_registration = 0: an open event; students do not sign up.
ALTER TABLE activities
    MODIFY end_at DATETIME NULL,
    ADD COLUMN requires_registration TINYINT(1) NOT NULL DEFAULT 1 AFTER max_participants;

-- New activities now start as draft + pending, and the admin's approval
-- publishes them. An office draft saved before this change is approved but
-- still a draft, so nothing would ever publish it: send it to the queue.
-- Statuses of published activities are corrected on the next page load.
UPDATE activities
   SET approval_status = 'pending', reviewed_by = NULL, reviewed_at = NULL
 WHERE status = 'draft' AND approval_status = 'approved';
