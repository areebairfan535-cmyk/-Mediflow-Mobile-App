-- Tax RULES per market, not just tax rates (§23).
--
-- `countries` already carried the rate, and TaxRules::forCountry carried the
-- behaviour — in a match statement, in PHP, under a docblock citing §23's
-- "do not hard-code country behavior". So a platform admin could open a new
-- market at runtime and set its rate, its currency and its date format, but
-- not the one thing that decides what the arithmetic does.
--
-- Every market added that way fell to the default: tax added on top, labelled
-- "Tax". Open Ireland or Australia — both VAT/GST-INCLUSIVE — and every
-- invoice overcharges by the rate, 120.00 where it should read 100.00, with
-- no fix short of editing PHP and redeploying.
--
-- tax_mode is the strategy TaxRules returns; tax_label is what prints on the
-- invoice, because "GST", "VAT" and "Sales Tax" are the same idea wearing
-- the name the local revenue office expects.

ALTER TABLE `countries`
    ADD COLUMN `tax_mode` ENUM('exclusive','inclusive','exempt')
        NOT NULL DEFAULT 'exclusive' AFTER `default_tax_rate`,
    ADD COLUMN `tax_label` VARCHAR(30) NOT NULL DEFAULT 'Tax' AFTER `tax_mode`;

-- The four markets §23 names, with the behaviour that was hard-coded before.
UPDATE `countries` SET `tax_mode` = 'exclusive', `tax_label` = 'GST'        WHERE `code` = 'PK';
UPDATE `countries` SET `tax_mode` = 'exclusive', `tax_label` = 'VAT'        WHERE `code` = 'AE';
UPDATE `countries` SET `tax_mode` = 'inclusive', `tax_label` = 'VAT'        WHERE `code` = 'GB';
UPDATE `countries` SET `tax_mode` = 'exclusive', `tax_label` = 'Sales Tax'  WHERE `code` = 'US';
UPDATE `countries` SET `tax_mode` = 'exclusive', `tax_label` = 'GST'        WHERE `code` = 'SG';
