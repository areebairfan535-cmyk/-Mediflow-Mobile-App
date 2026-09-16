-- The patient's identity document (§2 — a complete profile).
--
-- A patient registering from their phone gives their name, email, phone and
-- ID card. The card number is how a clinic tells two Fatima Noors apart at
-- the desk, and the expiry is the part that changes: an expired card is not
-- valid identification for insurance, and the patient should see that coming
-- on their own profile rather than at the counter.
--
-- Nullable on purpose: charts opened by the front desk for a walk-in, and
-- every chart that predates this column, have no card on file yet.

ALTER TABLE `patients`
    ADD COLUMN `national_id` VARCHAR(32) NULL AFTER `gender`,
    ADD COLUMN `national_id_expiry` DATE NULL AFTER `national_id`,
    ADD INDEX `idx_patients_national_id` (`organization_id`, `national_id`);
