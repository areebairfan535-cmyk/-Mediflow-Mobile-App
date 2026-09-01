-- =====================================================================
-- 003 CLINICAL — appointments, encounters and everything hanging off them
-- Plan refs: §4 consultation workflow, §5 medical records, §19 documents
--
-- ENCOUNTER is the hub of the whole system: every diagnosis, prescription,
-- lab order, procedure, note and invoice links back to one encounter.
-- =====================================================================

CREATE TABLE IF NOT EXISTS appointments (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id  BIGINT UNSIGNED NOT NULL,
    patient_id       BIGINT UNSIGNED NOT NULL,
    doctor_id        BIGINT UNSIGNED NOT NULL,
    scheduled_at     DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    type             ENUM('consultation','followup','procedure','teleconsult') NOT NULL DEFAULT 'consultation',
    status           ENUM('booked','confirmed','arrived','in_consultation','completed','cancelled','no_show')
                     NOT NULL DEFAULT 'booked',
    reason           VARCHAR(500) NULL,
    cancelled_reason VARCHAR(500) NULL,
    rescheduled_from BIGINT UNSIGNED NULL,       -- §3 reschedule support
    booked_by        BIGINT UNSIGNED NULL,        -- patient self-book vs receptionist
    created_by       BIGINT UNSIGNED NULL,
    updated_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    INDEX idx_apt_doctor_time (organization_id, doctor_id, scheduled_at),
    INDEX idx_apt_patient (organization_id, patient_id),
    INDEX idx_apt_status (organization_id, status),
    CONSTRAINT fk_apt_org     FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_apt_patient FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_apt_doctor  FOREIGN KEY (doctor_id)
        REFERENCES doctors (id) ON DELETE CASCADE,
    CONSTRAINT fk_apt_resched FOREIGN KEY (rescheduled_from)
        REFERENCES appointments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One visit. The clinical + billing hub.
CREATE TABLE IF NOT EXISTS encounters (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id  BIGINT UNSIGNED NOT NULL,
    patient_id       BIGINT UNSIGNED NOT NULL,
    doctor_id        BIGINT UNSIGNED NOT NULL,
    appointment_id   BIGINT UNSIGNED NULL,        -- NULL = walk-in
    encounter_no     VARCHAR(32)  NOT NULL,
    type             ENUM('outpatient','followup','emergency','teleconsult') NOT NULL DEFAULT 'outpatient',
    status           ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
    chief_complaint  VARCHAR(500) NULL,
    symptoms         TEXT NULL,
    examination      TEXT NULL,
    -- Vitals captured at the visit.
    bp_systolic      SMALLINT UNSIGNED NULL,
    bp_diastolic     SMALLINT UNSIGNED NULL,
    pulse            SMALLINT UNSIGNED NULL,
    temperature_c    DECIMAL(4,1) NULL,
    weight_kg        DECIMAL(5,2) NULL,
    height_cm        DECIMAL(5,2) NULL,
    followup_on      DATE NULL,
    started_at       DATETIME NULL,
    completed_at     DATETIME NULL,
    created_by       BIGINT UNSIGNED NULL,
    updated_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    UNIQUE KEY uniq_encounter_no (organization_id, encounter_no),
    INDEX idx_enc_patient (organization_id, patient_id),
    INDEX idx_enc_doctor_status (organization_id, doctor_id, status),
    CONSTRAINT fk_enc_org     FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_enc_patient FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_enc_doctor  FOREIGN KEY (doctor_id)
        REFERENCES doctors (id) ON DELETE CASCADE,
    CONSTRAINT fk_enc_apt     FOREIGN KEY (appointment_id)
        REFERENCES appointments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnoses (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    icd10_code      VARCHAR(16)  NULL,
    description     VARCHAR(500) NOT NULL,
    type            ENUM('primary','secondary','provisional','differential') NOT NULL DEFAULT 'primary',
    notes           TEXT NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_diag_encounter (organization_id, encounter_id),
    INDEX idx_diag_patient (organization_id, patient_id),
    CONSTRAINT fk_diag_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_diag_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE CASCADE,
    CONSTRAINT fk_diag_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drug catalogue, per organization. Reusable so doctors SELECT, not type (§4).
CREATE TABLE IF NOT EXISTS medications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,      -- Amoxicillin
    brand_name      VARCHAR(200) NULL,          -- Augmentin
    form            VARCHAR(60)  NULL,          -- tablet, syrup, injection
    strength        VARCHAR(60)  NULL,          -- 500mg
    default_dosage    VARCHAR(120) NULL,        -- pre-filled defaults = fewer keystrokes
    default_frequency VARCHAR(120) NULL,
    default_duration  VARCHAR(120) NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_med_org_name (organization_id, name),
    CONSTRAINT fk_med_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prescriptions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    doctor_id       BIGINT UNSIGNED NOT NULL,
    prescription_no VARCHAR(32) NOT NULL,
    status          ENUM('draft','issued','cancelled') NOT NULL DEFAULT 'draft',
    general_advice  TEXT NULL,
    pdf_path        VARCHAR(500) NULL,          -- §4 generated prescription PDF
    issued_at       DATETIME NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_prescription_no (organization_id, prescription_no),
    INDEX idx_rx_patient (organization_id, patient_id),
    INDEX idx_rx_encounter (organization_id, encounter_id),
    CONSTRAINT fk_rx_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_doc FOREIGN KEY (doctor_id)
        REFERENCES doctors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One line per drug: medicine + dosage + frequency + duration + instructions (§4)
CREATE TABLE IF NOT EXISTS prescription_items (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id  BIGINT UNSIGNED NOT NULL,
    prescription_id  BIGINT UNSIGNED NOT NULL,
    medication_id    BIGINT UNSIGNED NULL,       -- NULL = free-text drug
    medication_name  VARCHAR(200) NOT NULL,      -- snapshot, survives catalogue edits
    dosage           VARCHAR(120) NULL,          -- 1 tablet
    frequency        VARCHAR(120) NULL,          -- twice a day
    duration         VARCHAR(120) NULL,          -- 7 days
    instructions     VARCHAR(500) NULL,          -- after meals
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME NULL,
    INDEX idx_rxi_prescription (prescription_id),
    CONSTRAINT fk_rxi_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_rxi_rx  FOREIGN KEY (prescription_id)
        REFERENCES prescriptions (id) ON DELETE CASCADE,
    CONSTRAINT fk_rxi_med FOREIGN KEY (medication_id)
        REFERENCES medications (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_orders (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    doctor_id       BIGINT UNSIGNED NULL,
    order_no        VARCHAR(32) NOT NULL,
    status          ENUM('ordered','sample_collected','processing','completed','cancelled')
                    NOT NULL DEFAULT 'ordered',
    priority        ENUM('routine','urgent','stat') NOT NULL DEFAULT 'routine',
    clinical_notes  TEXT NULL,
    ordered_at      DATETIME NULL,
    completed_at    DATETIME NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_lab_order_no (organization_id, order_no),
    INDEX idx_lab_patient (organization_id, patient_id),
    INDEX idx_lab_status (organization_id, status),
    CONSTRAINT fk_lab_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_results (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    lab_order_id    BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    test_name       VARCHAR(200) NOT NULL,       -- CBC, Hemoglobin
    value           VARCHAR(120) NULL,
    unit            VARCHAR(40)  NULL,
    reference_range VARCHAR(120) NULL,
    flag            ENUM('normal','low','high','critical') NULL,
    comments        TEXT NULL,
    reported_at     DATETIME NULL,
    reported_by     BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_result_order (lab_order_id),
    INDEX idx_result_patient (organization_id, patient_id),
    CONSTRAINT fk_result_org   FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_result_order FOREIGN KEY (lab_order_id)
        REFERENCES lab_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_result_pat   FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procedures (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    doctor_id       BIGINT UNSIGNED NULL,
    service_id      BIGINT UNSIGNED NULL,        -- links to billable service (§6)
    name            VARCHAR(200) NOT NULL,
    cpt_code        VARCHAR(16)  NULL,           -- procedure billing code
    site            VARCHAR(120) NULL,           -- tooth #26, left knee
    outcome         TEXT NULL,
    performed_at    DATETIME NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_proc_encounter (organization_id, encounter_id),
    INDEX idx_proc_patient (organization_id, patient_id),
    CONSTRAINT fk_proc_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_proc_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE CASCADE,
    CONSTRAINT fk_proc_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinical_notes (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    type            ENUM('soap','progress','discharge','referral','general') NOT NULL DEFAULT 'general',
    body            TEXT NOT NULL,
    is_ai_drafted   TINYINT(1) NOT NULL DEFAULT 0,   -- §9 AI documentation assistant
    approved_by     BIGINT UNSIGNED NULL,            -- §9 human confirmation required
    approved_at     DATETIME NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_note_encounter (organization_id, encounter_id),
    CONSTRAINT fk_note_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_note_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE CASCADE,
    CONSTRAINT fk_note_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- §19: binary files live in object storage; only metadata + ACL live here.
CREATE TABLE IF NOT EXISTS medical_documents (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NULL,
    category        ENUM('prescription','lab_report','imaging','invoice','insurance','discharge','consent','other')
                    NOT NULL DEFAULT 'other',
    title           VARCHAR(255) NOT NULL,
    storage_path    VARCHAR(500) NOT NULL,       -- path/key, NOT the bytes
    mime_type       VARCHAR(120) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum_sha256 CHAR(64) NULL,
    visibility      ENUM('clinic_only','patient_visible') NOT NULL DEFAULT 'clinic_only',
    uploaded_by     BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_doc_patient (organization_id, patient_id),
    INDEX idx_doc_category (organization_id, category),
    CONSTRAINT fk_doc_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
