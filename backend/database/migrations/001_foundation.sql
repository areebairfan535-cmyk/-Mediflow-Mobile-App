-- =====================================================================
-- 001 FOUNDATION — localization, tenancy, identity, RBAC, sessions
-- Plan refs: §10 multi-tenancy, §11 auth/authz, §23 localization
-- =====================================================================

-- Localization engine (§23): country behaviour is DATA, never hard-coded.
CREATE TABLE IF NOT EXISTS countries (
    id               SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code             CHAR(2)      NOT NULL,             -- ISO 3166-1: PK, US, GB, AE
    name             VARCHAR(100) NOT NULL,
    currency_code    CHAR(3)      NOT NULL,             -- ISO 4217: PKR, USD, GBP, AED
    currency_symbol  VARCHAR(8)   NOT NULL,
    timezone         VARCHAR(64)  NOT NULL,
    date_format      VARCHAR(32)  NOT NULL DEFAULT 'd/m/Y',
    default_tax_rate DECIMAL(6,4) NOT NULL DEFAULT 0,   -- 0.1700 = 17%
    invoice_prefix   VARCHAR(16)  NOT NULL DEFAULT 'INV',
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME    NULL,
    updated_at       DATETIME    NULL,
    UNIQUE KEY uniq_country_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant root (§10). Every tenant-scoped row carries organization_id.
CREATE TABLE IF NOT EXISTS organizations (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    slug             VARCHAR(120) NOT NULL,
    country_id       SMALLINT UNSIGNED NOT NULL,
    email            VARCHAR(255) NULL,
    phone            VARCHAR(32)  NULL,
    address          VARCHAR(500) NULL,
    city             VARCHAR(120) NULL,
    logo_path        VARCHAR(500) NULL,
    -- Per-tenant overrides of the country defaults (NULL = inherit country).
    currency_code    CHAR(3)      NULL,
    timezone         VARCHAR(64)  NULL,
    tax_rate         DECIMAL(6,4) NULL,
    invoice_prefix   VARCHAR(16)  NULL,
    next_invoice_no  BIGINT UNSIGNED NOT NULL DEFAULT 1,
    status           ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    UNIQUE KEY uniq_org_slug (slug),
    INDEX idx_org_status (status),
    CONSTRAINT fk_org_country FOREIGN KEY (country_id) REFERENCES countries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global identity. A user is NOT tenant-scoped: the same person can belong to
-- several organizations (see organization_users). Platform staff have no org.
CREATE TABLE IF NOT EXISTS users (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    email             VARCHAR(255) NOT NULL,
    phone             VARCHAR(32)  NULL,
    password          VARCHAR(255) NOT NULL,
    avatar_path       VARCHAR(500) NULL,
    locale            VARCHAR(10)  NOT NULL DEFAULT 'en',
    is_platform_admin TINYINT(1)   NOT NULL DEFAULT 0,   -- super admin (§21)
    status            ENUM('active','disabled') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    last_login_at     DATETIME NULL,
    failed_logins     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until      DATETIME NULL,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    UNIQUE KEY uniq_user_email (email),
    INDEX idx_user_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RBAC (§11). System roles are global templates (organization_id NULL);
-- an organization may also define its own custom roles.
CREATE TABLE IF NOT EXISTS roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NULL,
    slug            VARCHAR(64)  NOT NULL,   -- org_owner, doctor, receptionist, patient
    name            VARCHAR(120) NOT NULL,
    description     VARCHAR(500) NULL,
    is_system       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_role_scope (organization_id, slug),
    CONSTRAINT fk_roles_org FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(96)  NOT NULL,   -- 'invoice.create', 'encounter.view'
    name        VARCHAR(150) NOT NULL,
    module      VARCHAR(64)  NOT NULL,   -- clinical, billing, insurance, admin
    description VARCHAR(500) NULL,
    created_at  DATETIME NULL,
    UNIQUE KEY uniq_permission_slug (slug),
    INDEX idx_permission_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id)
        REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id)
        REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership: which user belongs to which organization, in which role.
-- This is the table every tenant authorization check reads.
CREATE TABLE IF NOT EXISTS organization_users (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    role_id         INT UNSIGNED    NOT NULL,
    job_title       VARCHAR(120) NULL,
    status          ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
    invited_at      DATETIME NULL,
    joined_at       DATETIME NULL,
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    UNIQUE KEY uniq_org_user (organization_id, user_id),
    INDEX idx_ou_user (user_id),
    INDEX idx_ou_role (role_id),
    CONSTRAINT fk_ou_org  FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_ou_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ou_role FOREIGN KEY (role_id)
        REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access + refresh tokens with device/session management (§11).
-- Only SHA-256 hashes are stored; plaintext is returned to the client once.
CREATE TABLE IF NOT EXISTS auth_tokens (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    type          ENUM('access','refresh') NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    parent_id     BIGINT UNSIGNED NULL,      -- access token -> its refresh token
    active_org_id BIGINT UNSIGNED NULL,      -- tenant this session is scoped to
    device_name   VARCHAR(150) NULL,
    device_id     VARCHAR(120) NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(500) NULL,
    expires_at    DATETIME NOT NULL,
    revoked_at    DATETIME NULL,
    last_used_at  DATETIME NULL,
    created_at    DATETIME NULL,
    UNIQUE KEY uniq_token_hash (token_hash),
    INDEX idx_token_user_type (user_id, type),
    INDEX idx_token_expiry (expires_at),
    CONSTRAINT fk_token_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_token_parent FOREIGN KEY (parent_id)
        REFERENCES auth_tokens (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting (§17). Counter buckets keyed by identity+route window.
CREATE TABLE IF NOT EXISTS rate_limits (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_key   VARCHAR(191) NOT NULL,   -- sha1(ip|user|route)
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    UNIQUE KEY uniq_bucket (bucket_key),
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
