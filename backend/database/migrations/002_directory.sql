-- =====================================================================
-- 002 DIRECTORY — patients, doctors, staff and per-patient clinical flags
-- Plan refs: §5 medical records, §10 tenancy
-- Every table here is tenant-scoped: organization_id is NOT NULL.
-- =====================================================================

CREATE TABLE IF NOT EXISTS patients (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id  BIGINT UNSIGNED NOT NULL,
    user_id          BIGINT UNSIGNED NULL,       -- NULL = walk-in, no app login yet
    mrn              VARCHAR(32)  NOT NULL,      -- Medical Record Number, unique per org
    first_name       VARCHAR(120) NOT NULL,
    last_name        VARCHAR(120) NOT NULL,
    date_of_birth    DATE NULL,
    gender           ENUM('male','female','other','unknown') NOT NULL DEFAULT 'unknown',
    phone            VARCHAR(32)  NULL,
    email            VARCHAR(255) NULL,
    address          VARCHAR(500) NULL,
    city             VARCHAR(120) NULL,
    blood_group      VARCHAR(8)   NULL,          -- §3 patient profile
    emergency_name   VARCHAR(150) NULL,
    emergency_phone  VARCHAR(32)  NULL,
    emergency_relation VARCHAR(60) NULL,
    notes            TEXT NULL,
    status           ENUM('active','inactive','merged') NOT NULL DEFAULT 'active',
    created_by       BIGINT UNSIGNED NULL,
    updated_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    UNIQUE KEY uniq_patient_mrn (organization_id, mrn),
    INDEX idx_patient_org_name (organization_id, last_name, first_name),
    INDEX idx_patient_user (user_id),
    INDEX idx_patient_phone (organization_id, phone),
    CONSTRAINT fk_patient_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_patient_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctors (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id   BIGINT UNSIGNED NOT NULL,
    user_id           BIGINT UNSIGNED NOT NULL,
    specialty         VARCHAR(120) NOT NULL,
    qualification     VARCHAR(255) NULL,
    license_no        VARCHAR(64)  NULL,
    experience_years  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    consultation_fee  DECIMAL(12,2) NOT NULL DEFAULT 0,
    followup_fee      DECIMAL(12,2) NULL,
    bio               TEXT NULL,
    room              VARCHAR(60)  NULL,
    slot_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    is_accepting      TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    UNIQUE KEY uniq_doctor_user (organization_id, user_id),
    INDEX idx_doctor_specialty (organization_id, specialty),
    CONSTRAINT fk_doctor_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_doctor_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly availability template per doctor.
CREATE TABLE IF NOT EXISTS doctor_schedules (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    doctor_id       BIGINT UNSIGNED NOT NULL,
    day_of_week     TINYINT UNSIGNED NOT NULL,   -- 1=Mon .. 7=Sun (ISO-8601)
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_sched_doctor_day (doctor_id, day_of_week),
    CONSTRAINT fk_sched_org    FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_sched_doctor FOREIGN KEY (doctor_id)
        REFERENCES doctors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Non-doctor employees: nurse, receptionist, accountant, billing, lab, pharmacist (§10)
CREATE TABLE IF NOT EXISTS staff (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    employee_no     VARCHAR(32)  NULL,
    department      VARCHAR(120) NULL,
    designation     VARCHAR(120) NULL,
    hired_at        DATE NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_staff_user (organization_id, user_id),
    CONSTRAINT fk_staff_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- §5 core entities: Allergy and Medical Condition
CREATE TABLE IF NOT EXISTS allergies (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    substance       VARCHAR(150) NOT NULL,
    reaction        VARCHAR(255) NULL,
    severity        ENUM('mild','moderate','severe','life_threatening') NOT NULL DEFAULT 'mild',
    noted_on        DATE NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_allergy_patient (organization_id, patient_id),
    CONSTRAINT fk_allergy_org     FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_allergy_patient FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medical_conditions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,       -- Diabetes Type 2, Hypertension
    icd10_code      VARCHAR(16)  NULL,
    status          ENUM('active','resolved','chronic') NOT NULL DEFAULT 'active',
    diagnosed_on    DATE NULL,
    notes           TEXT NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_condition_patient (organization_id, patient_id),
    CONSTRAINT fk_condition_org     FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_condition_patient FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
