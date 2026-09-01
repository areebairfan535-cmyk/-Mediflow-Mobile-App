-- 009_notification_dismiss.sql
--
-- Let a patient clear their inbox without erasing the record.
--
-- Notifications were kept for ever and had no way out: `read_at` only recorded
-- that someone had seen a line, and nothing ever left the list. After a year
-- of appointments and invoices a patient is scrolling past hundreds of rows to
-- find this week's.
--
-- Deleting the row would be the easy fix and the wrong one: "was the patient
-- reminded?" is a question a clinic has to be able to answer, and §20 makes
-- the notification the record of that. So dismissing hides it from the person
-- who dismissed it, and the row stays exactly where it was.

ALTER TABLE notifications
    ADD COLUMN dismissed_at DATETIME NULL AFTER read_at,
    ADD INDEX idx_notif_inbox (user_id, channel, dismissed_at, created_at);
