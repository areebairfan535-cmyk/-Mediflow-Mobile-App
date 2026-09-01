-- =====================================================================
-- 006 SYSTEM — notifications, audit logs, SaaS subscriptions
-- Plan refs: §16 audit logging, §20 notifications, §22 subscriptions
-- =====================================================================

-- §20: one table for every channel. Push/Email/SMS/WhatsApp all queue here.
CREATE TABLE IF NOT EXISTS notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NULL,        -- NULL = platform-level notice
    user_id         BIGINT UNSIGNED NULL,        -- recipient (NULL = external only)
    channel         ENUM('in_app','push','email','sms','whatsapp') NOT NULL DEFAULT 'in_app',
    event           VARCHAR(80) NOT NULL,        -- appointment.booked, invoice.overdue
    title           VARCHAR(255) NOT NULL,
    body            TEXT NULL,
    -- Generic pointer to whatever the notification is about.
    subject_type    VARCHAR(60) NULL,            -- 'invoice', 'appointment', 'claim'
    subject_id      BIGINT UNSIGNED NULL,
    payload         JSON NULL,
    to_address      VARCHAR(255) NULL,           -- email / phone / device token
    status          ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
    attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error           VARCHAR(500) NULL,
    scheduled_for   DATETIME NULL,               -- appointment reminders
    sent_at         DATETIME NULL,
    read_at         DATETIME NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    INDEX idx_notif_user (user_id, status),
    INDEX idx_notif_org (organization_id, event),
    INDEX idx_notif_due (status, scheduled_for),
    CONSTRAINT fk_notif_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- §16: who touched which patient/financial record, when, from where, and
-- for critical changes the old/new values. Append-only — never UPDATE these.
CREATE TABLE IF NOT EXISTS audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NULL,        -- NULL = unauthenticated attempt
    action          VARCHAR(80) NOT NULL,        -- view, create, update, delete, login, export
    resource_type   VARCHAR(60) NOT NULL,        -- patient, invoice, encounter, payment
    resource_id     BIGINT UNSIGNED NULL,
    patient_id      BIGINT UNSIGNED NULL,        -- denormalised: "who saw MY record?"
    old_values      JSON NULL,
    new_values      JSON NULL,
    route           VARCHAR(191) NULL,
    method          VARCHAR(10)  NULL,
    ip_address      VARCHAR(45)  NULL,
    user_agent      VARCHAR(500) NULL,
    request_id      CHAR(32) NULL,               -- correlates all rows of one request
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_org_time (organization_id, created_at),
    INDEX idx_audit_user (user_id, created_at),
    INDEX idx_audit_resource (resource_type, resource_id),
    INDEX idx_audit_patient (patient_id, created_at),
    CONSTRAINT fk_audit_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- §22: Free / Starter / Professional / Enterprise
CREATE TABLE IF NOT EXISTS plans (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug               VARCHAR(40)  NOT NULL,
    name               VARCHAR(100) NOT NULL,
    description        VARCHAR(500) NULL,
    price_monthly      DECIMAL(12,2) NOT NULL DEFAULT 0,
    price_yearly       DECIMAL(12,2) NULL,
    currency_code      CHAR(3) NOT NULL DEFAULT 'USD',
    -- Usage limits (§22). NULL = unlimited.
    max_doctors        INT UNSIGNED NULL,
    max_staff          INT UNSIGNED NULL,
    max_patients       INT UNSIGNED NULL,
    max_storage_mb     INT UNSIGNED NULL,
    max_invoices_month INT UNSIGNED NULL,
    max_appointments_month INT UNSIGNED NULL,
    max_ai_calls_month INT UNSIGNED NULL,
    features           JSON NULL,                -- {"insurance":true,"ai_billing":false}
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME NULL,
    updated_at         DATETIME NULL,
    UNIQUE KEY uniq_plan_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id    BIGINT UNSIGNED NOT NULL,
    plan_id            INT UNSIGNED NOT NULL,
    status             ENUM('trialing','active','past_due','cancelled','expired')
                       NOT NULL DEFAULT 'trialing',
    billing_cycle      ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    currency_code      CHAR(3) NOT NULL,
    amount             DECIMAL(12,2) NOT NULL DEFAULT 0,
    trial_ends_at      DATETIME NULL,
    current_period_start DATE NULL,
    current_period_end   DATE NULL,
    cancelled_at       DATETIME NULL,
    gateway            VARCHAR(60)  NULL,
    gateway_ref        VARCHAR(191) NULL,
    created_at         DATETIME NULL,
    updated_at         DATETIME NULL,
    INDEX idx_sub_org (organization_id, status),
    CONSTRAINT fk_sub_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id)
        REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-period usage counters, so limits can be enforced and shown.
CREATE TABLE IF NOT EXISTS subscription_items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    metric          VARCHAR(60) NOT NULL,        -- doctors, patients, invoices, ai_calls
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    included_qty    INT UNSIGNED NULL,
    used_qty        INT UNSIGNED NOT NULL DEFAULT 0,
    unit_price      DECIMAL(12,4) NOT NULL DEFAULT 0,   -- overage price
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_sub_metric_period (subscription_id, metric, period_start),
    CONSTRAINT fk_si_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_si_sub FOREIGN KEY (subscription_id)
        REFERENCES subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration bookkeeping.
CREATE TABLE IF NOT EXISTS migrations (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(191) NOT NULL,
    batch      INT UNSIGNED NOT NULL DEFAULT 1,
    ran_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_migration (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
