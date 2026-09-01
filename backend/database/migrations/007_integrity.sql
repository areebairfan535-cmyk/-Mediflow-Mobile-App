-- 007_integrity.sql
--
-- One login, one chart.
--
-- `patients.user_id` was only ever set by the account-linking path, which
-- checked the patient side ("does this patient already have an account?") but
-- not the account side. Linking an existing login to a second patient record
-- therefore succeeded, and the patient app — which resolves "my chart" from
-- user_id — then picked one of the two rows and showed half a history.
--
-- The service now refuses it, and this index makes the database refuse it too:
-- an application check alone would be undone by the next importer, seeder or
-- console fix that writes the column directly.
--
-- MySQL and MariaDB allow any number of NULLs in a UNIQUE index, so the many
-- patients with no app account are unaffected.

ALTER TABLE patients
    ADD UNIQUE KEY uniq_patient_user (organization_id, user_id);
