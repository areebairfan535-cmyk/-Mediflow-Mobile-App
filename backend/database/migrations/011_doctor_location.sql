-- 011_doctor_location.sql
--
-- Where a doctor actually sits.
--
-- §3 asks a patient to be able to find a doctor "by specialty and location".
-- Specialty was already there; location was not, and there was nowhere to put
-- it. `room` is the nearest thing on `doctors`, but a room number only tells
-- you where to go once you have arrived — it cannot answer "which of your
-- branches is this doctor at", which is the question a patient asks before
-- booking.
--
-- A full `branches` table was the other option, and it is the right answer for
-- an organization that runs its sites as separate units: their own stock, own
-- staff rosters, own opening hours. Nothing else in this schema is split that
-- way — appointments, invoices and encounters are all keyed on the
-- organization alone — so introducing branches here would mean a foreign key
-- that only one filter reads, and a second place for a doctor's whereabouts
-- to disagree with the first.
--
-- So location is modelled exactly like specialty: a plain string on the
-- doctor, indexed alongside the organization, filtered by exact match and
-- offered to the patient as a list of the values actually in use. If branches
-- ever earn a table of their own, this column is what gets migrated into it.

ALTER TABLE doctors
    ADD COLUMN location VARCHAR(120) NULL AFTER specialty;

-- Mirrors idx_doctor_specialty. The patient's filter is always scoped to one
-- organization, so the organization column leads.
ALTER TABLE doctors
    ADD INDEX idx_doctor_location (organization_id, location);
