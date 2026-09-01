-- =====================================================================
-- 005 INSURANCE & CLAIMS
-- Plan ref: §8. Not in MVP (§26 / module matrix) but the schema is created
-- in Phase 1 so clinical + billing rows can reference policies from day one
-- without a later destructive migration.
-- =====================================================================

CREATE TABLE IF NOT EXISTS insurance_providers (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id   BIGINT UNSIGNED NULL,        -- NULL = platform-wide provider
    country_id        SMALLINT UNSIGNED NULL,
    name              VARCHAR(200) NOT NULL,
    code              VARCHAR(40)  NULL,
    contact_email     VARCHAR(255) NULL,
    contact_phone     VARCHAR(32)  NULL,
    portal_url        VARCHAR(500) NULL,
    claim_format      VARCHAR(60)  NULL,           -- e.g. 'manual', 'x12_837p'
    avg_settle_days   SMALLINT UNSIGNED NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    INDEX idx_provider_org (organization_id),
    CONSTRAINT fk_provider_org     FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_provider_country FOREIGN KEY (country_id)
        REFERENCES countries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- §8 patient insurance profile: provider, policy number, member ID, coverage, expiry
CREATE TABLE IF NOT EXISTS insurance_policies (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id       BIGINT UNSIGNED NOT NULL,
    patient_id            BIGINT UNSIGNED NOT NULL,
    insurance_provider_id BIGINT UNSIGNED NOT NULL,
    policy_number         VARCHAR(120) NOT NULL,
    member_id             VARCHAR(120) NULL,
    group_number          VARCHAR(120) NULL,
    policy_holder_name    VARCHAR(200) NULL,
    relation_to_patient   VARCHAR(60)  NULL,       -- self, spouse, child
    coverage_type         VARCHAR(120) NULL,
    coverage_amount       DECIMAL(14,2) NULL,      -- annual ceiling
    coverage_used         DECIMAL(14,2) NOT NULL DEFAULT 0,
    copay_percent         DECIMAL(5,2) NULL,       -- patient's share
    deductible            DECIMAL(14,2) NULL,
    valid_from            DATE NULL,
    valid_to              DATE NULL,
    is_primary            TINYINT(1) NOT NULL DEFAULT 1,
    status                ENUM('active','expired','suspended') NOT NULL DEFAULT 'active',
    created_at            DATETIME NULL,
    updated_at            DATETIME NULL,
    INDEX idx_policy_patient (organization_id, patient_id),
    INDEX idx_policy_number (organization_id, policy_number),
    CONSTRAINT fk_policy_org      FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_policy_patient  FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_policy_provider FOREIGN KEY (insurance_provider_id)
        REFERENCES insurance_providers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- §8 claim statuses: Draft, Submitted, Processing, Approved,
-- Partially Approved, Rejected, Resubmission, Paid
CREATE TABLE IF NOT EXISTS claims (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id       BIGINT UNSIGNED NOT NULL,
    patient_id            BIGINT UNSIGNED NOT NULL,
    invoice_id            BIGINT UNSIGNED NOT NULL,
    encounter_id          BIGINT UNSIGNED NULL,
    insurance_policy_id   BIGINT UNSIGNED NOT NULL,
    claim_no              VARCHAR(40) NOT NULL,
    external_claim_no     VARCHAR(120) NULL,       -- number the insurer assigns
    status                ENUM('draft','submitted','processing','approved',
                               'partially_approved','rejected','resubmission','paid')
                          NOT NULL DEFAULT 'draft',
    currency_code         CHAR(3) NOT NULL,
    claimed_amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
    approved_amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
    patient_responsibility DECIMAL(14,2) NOT NULL DEFAULT 0,
    -- §8: store rejection reasons and supporting docs for resubmission + analytics
    rejection_code        VARCHAR(60)  NULL,
    rejection_reason      VARCHAR(1000) NULL,
    resubmission_of       BIGINT UNSIGNED NULL,
    submission_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- §9 AI claim assistant output, advisory only.
    ai_risk_score         DECIMAL(5,2) NULL,
    ai_missing_items      JSON NULL,
    submitted_at          DATETIME NULL,
    decided_at            DATETIME NULL,
    paid_at               DATETIME NULL,
    created_by            BIGINT UNSIGNED NULL,
    updated_by            BIGINT UNSIGNED NULL,
    created_at            DATETIME NULL,
    updated_at            DATETIME NULL,
    UNIQUE KEY uniq_claim_no (organization_id, claim_no),
    INDEX idx_claim_status (organization_id, status),
    INDEX idx_claim_patient (organization_id, patient_id),
    INDEX idx_claim_invoice (invoice_id),
    CONSTRAINT fk_claim_org    FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_claim_pat    FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_claim_inv    FOREIGN KEY (invoice_id)
        REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_claim_policy FOREIGN KEY (insurance_policy_id)
        REFERENCES insurance_policies (id),
    CONSTRAINT fk_claim_resub  FOREIGN KEY (resubmission_of)
        REFERENCES claims (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    claim_id        BIGINT UNSIGNED NOT NULL,
    invoice_item_id BIGINT UNSIGNED NULL,
    billing_code    VARCHAR(40)  NULL,           -- CPT / local code sent to insurer
    diagnosis_code  VARCHAR(16)  NULL,           -- ICD-10 justifying the charge
    description     VARCHAR(300) NOT NULL,
    quantity        DECIMAL(10,2) NOT NULL DEFAULT 1,
    claimed_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
    approved_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status          ENUM('claimed','approved','reduced','rejected') NOT NULL DEFAULT 'claimed',
    rejection_reason VARCHAR(500) NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_ci_claim (claim_id),
    CONSTRAINT fk_ci_org   FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_claim FOREIGN KEY (claim_id)
        REFERENCES claims (id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_item  FOREIGN KEY (invoice_item_id)
        REFERENCES invoice_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
