-- A lab order says WHICH tests (§4, §5).
--
-- lab_orders carried a priority and a note and nothing else, so "lab test
-- ordered" was the whole of what the patient, the lab and the owner could
-- read. The tests the doctor actually recommended are rows here, one each,
-- with a price where the clinic's own lab has one.
--
-- lab_name and total_charge are filled when the results come back: the
-- patient may have gone to the clinic's lab or to any lab of their choosing,
-- and the owner is told where, what, and for how much.

CREATE TABLE IF NOT EXISTS `lab_order_tests` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organization_id` BIGINT UNSIGNED NOT NULL,
    `lab_order_id`    BIGINT UNSIGNED NOT NULL,
    `test_name`       VARCHAR(200) NOT NULL,
    `price`           DECIMAL(12,2) NULL,
    `created_at`      DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lab_order_tests_order` (`organization_id`, `lab_order_id`),
    CONSTRAINT `fk_lab_order_tests_order` FOREIGN KEY (`lab_order_id`)
        REFERENCES `lab_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `lab_orders`
    ADD COLUMN `lab_name`     VARCHAR(200)  NULL AFTER `clinical_notes`,
    ADD COLUMN `total_charge` DECIMAL(12,2) NULL AFTER `lab_name`;
