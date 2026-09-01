-- =====================================================================
-- 004 BILLING — service catalogue, invoices, payments, refunds
-- Plan refs: §6 billing engine, §7 payments, §23 country/currency
--
-- KEY RULE from §6: invoices and payments are SEPARATE entities, so one
-- invoice can receive many payments (part-payment is the normal case).
-- =====================================================================

CREATE TABLE IF NOT EXISTS services (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(40)  NOT NULL,       -- CONSULT-GEN, LAB-CBC, XRAY-CHEST
    name            VARCHAR(200) NOT NULL,
    description     VARCHAR(500) NULL,
    department      VARCHAR(120) NULL,           -- OPD, Radiology, Laboratory, Dental
    category        ENUM('consultation','followup','procedure','lab','imaging','injection','room','other')
                    NOT NULL DEFAULT 'other',
    is_taxable      TINYINT(1) NOT NULL DEFAULT 1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_service_code (organization_id, code),
    INDEX idx_service_category (organization_id, category),
    CONSTRAINT fk_service_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price is per (service, country, currency) and time-bounded — §6 says each
-- service carries price/tax/discount/country/currency, and §23 forbids
-- hard-coding country behaviour. Same service, different price per market.
CREATE TABLE IF NOT EXISTS service_prices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    service_id      BIGINT UNSIGNED NOT NULL,
    country_id      SMALLINT UNSIGNED NULL,      -- NULL = applies to all countries
    currency_code   CHAR(3)      NOT NULL,
    price           DECIMAL(14,2) NOT NULL,
    tax_rate        DECIMAL(6,4) NULL,           -- NULL = inherit org/country rate
    max_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    effective_from  DATE NOT NULL,
    effective_to    DATE NULL,                   -- NULL = current price
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_price_lookup (organization_id, service_id, effective_from),
    CONSTRAINT fk_price_org     FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_price_service FOREIGN KEY (service_id)
        REFERENCES services (id) ON DELETE CASCADE,
    CONSTRAINT fk_price_country FOREIGN KEY (country_id)
        REFERENCES countries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    encounter_id    BIGINT UNSIGNED NULL,
    invoice_no      VARCHAR(40) NOT NULL,        -- org prefix + sequence
    status          ENUM('draft','issued','partially_paid','paid','overdue','cancelled','refunded')
                    NOT NULL DEFAULT 'draft',
    currency_code   CHAR(3) NOT NULL,
    -- Money is stored as DECIMAL, never FLOAT.
    subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
    grand_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
    -- Derived, kept as a stored column so "outstanding" queries stay simple.
    balance_due     DECIMAL(14,2) AS (grand_total - paid_total) STORED,
    -- Split of who owes what (§8): patient share vs insurance share.
    patient_payable   DECIMAL(14,2) NOT NULL DEFAULT 0,
    insurance_payable DECIMAL(14,2) NOT NULL DEFAULT 0,
    issue_date      DATE NULL,
    due_date        DATE NULL,
    notes           VARCHAR(1000) NULL,
    pdf_path        VARCHAR(500) NULL,
    issued_by       BIGINT UNSIGNED NULL,
    cancelled_reason VARCHAR(500) NULL,
    created_by      BIGINT UNSIGNED NULL,
    updated_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_invoice_no (organization_id, invoice_no),
    INDEX idx_inv_patient (organization_id, patient_id),
    INDEX idx_inv_status (organization_id, status),
    INDEX idx_inv_due (organization_id, due_date),
    CONSTRAINT fk_inv_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_enc FOREIGN KEY (encounter_id)
        REFERENCES encounters (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    service_id      BIGINT UNSIGNED NULL,
    -- Snapshots: an invoice must never change because the catalogue changed.
    service_code    VARCHAR(40)  NULL,
    description     VARCHAR(300) NOT NULL,
    quantity        DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price      DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_rate        DECIMAL(6,4)  NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    line_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
    -- Provenance: which clinical act produced this billable line.
    procedure_id    BIGINT UNSIGNED NULL,
    lab_order_id    BIGINT UNSIGNED NULL,
    is_ai_suggested TINYINT(1) NOT NULL DEFAULT 0,   -- §9 AI billing assistant
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NULL,
    INDEX idx_item_invoice (invoice_id),
    CONSTRAINT fk_item_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_inv FOREIGN KEY (invoice_id)
        REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_svc FOREIGN KEY (service_id)
        REFERENCES services (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Separate entity (§6). Many payments may settle one invoice.
CREATE TABLE IF NOT EXISTS payments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    patient_id      BIGINT UNSIGNED NOT NULL,
    receipt_no      VARCHAR(40) NOT NULL,
    method          ENUM('cash','bank_transfer','card','online','insurance','adjustment')
                    NOT NULL DEFAULT 'cash',
    status          ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'succeeded',
    currency_code   CHAR(3) NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    -- Gateway fields stay generic so any provider can be plugged in (§7, §13 Strategy).
    gateway         VARCHAR(60)  NULL,
    gateway_ref     VARCHAR(191) NULL,
    gateway_payload JSON NULL,
    paid_at         DATETIME NULL,
    received_by     BIGINT UNSIGNED NULL,
    notes           VARCHAR(500) NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_receipt_no (organization_id, receipt_no),
    INDEX idx_pay_invoice (invoice_id),
    INDEX idx_pay_patient (organization_id, patient_id),
    INDEX idx_pay_gateway_ref (gateway, gateway_ref),
    CONSTRAINT fk_pay_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id)
        REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_pat FOREIGN KEY (patient_id)
        REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    payment_id      BIGINT UNSIGNED NOT NULL,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    currency_code   CHAR(3) NOT NULL,
    reason          VARCHAR(500) NOT NULL,
    status          ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    gateway_ref     VARCHAR(191) NULL,
    approved_by     BIGINT UNSIGNED NULL,
    refunded_at     DATETIME NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_refund_payment (payment_id),
    INDEX idx_refund_invoice (invoice_id),
    CONSTRAINT fk_refund_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_refund_pay FOREIGN KEY (payment_id)
        REFERENCES payments (id) ON DELETE CASCADE,
    CONSTRAINT fk_refund_inv FOREIGN KEY (invoice_id)
        REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
