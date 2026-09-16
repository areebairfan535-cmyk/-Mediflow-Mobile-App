-- An appointment the clinic has put on hold (§3, §4).
--
-- Cancelling was the only way to stop a booking, and it burns the slot and
-- the patient's plans in one move. A hold keeps the slot and the booking,
-- says why, and can be lifted. The patient is told before they set out,
-- with the reason in front of them.

ALTER TABLE `appointments`
    MODIFY COLUMN `status` ENUM(
        'booked','confirmed','on_hold','arrived','in_consultation','completed','cancelled','no_show'
    ) NOT NULL DEFAULT 'booked',
    ADD COLUMN `hold_reason` VARCHAR(500) NULL AFTER `cancelled_reason`;
