-- Insurance a patient enters themselves waits for the clinic (§2, §7).
--
-- Until now every policy was typed in by staff who had the card in hand, so
-- a policy was active the moment it existed. A patient can now add their own
-- cover from the app, and nobody at the desk has seen that card yet — so it
-- lands as `pending`, counts for nothing (activePolicyFor only reads
-- `active`), and becomes real when someone with policy.manage approves it.
-- `rejected` keeps the refused entry, with the reason, so the patient is
-- told what to fix rather than watching their policy vanish.

ALTER TABLE `insurance_policies`
    MODIFY COLUMN `status` ENUM('pending','active','expired','suspended','rejected')
        NOT NULL DEFAULT 'active',
    ADD COLUMN `submitted_by` BIGINT UNSIGNED NULL AFTER `is_primary`,
    ADD COLUMN `reviewed_by`  BIGINT UNSIGNED NULL AFTER `submitted_by`,
    ADD COLUMN `reviewed_at`  DATETIME NULL AFTER `reviewed_by`,
    ADD COLUMN `review_note`  VARCHAR(500) NULL AFTER `reviewed_at`;
