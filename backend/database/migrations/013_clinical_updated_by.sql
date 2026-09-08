-- 013_clinical_updated_by.sql
--
-- Who changed a clinical record, not only when.
--
-- §5 asks that every sensitive record carry its creator AND its updater. Every
-- clinical table already had `created_by` (sometimes under a truer name —
-- `reported_by` on a lab result, `uploaded_by` on a document) and every one had
-- `updated_at`. None had `updated_by`.
--
-- So the row could say a patient's allergy was deactivated at 14:32 on Tuesday
-- and nothing at all about who decided that. The audit log holds the answer,
-- but a clinician reading the chart does not open the audit log — and an
-- allergy that quietly stopped being an allergy is exactly the change somebody
-- will need to ask about.
--
-- Only the tables that are actually updated get the column. A column nobody
-- ever writes is worse than an absent one: it reads as "nobody changed this"
-- when the truth is "we never recorded it".
--
--   allergies           deactivated when it turns out to be wrong
--   medical_conditions  status moves: active -> resolved, and back
--   lab_orders          marked completed when results are entered
--   clinical_notes      an AI draft is edited and approved
--   prescriptions       issued, then possibly cancelled
--
-- lab_results and medical_documents are left alone on purpose: nothing amends
-- them. A result is superseded by a new one and a document is replaced by a new
-- upload, which is the right shape for both.

ALTER TABLE allergies
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_allergy_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE medical_conditions
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_condition_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE lab_orders
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_lab_order_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE clinical_notes
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_note_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE prescriptions
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_prescription_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL;
