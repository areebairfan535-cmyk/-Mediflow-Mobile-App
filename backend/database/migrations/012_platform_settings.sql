-- 012_platform_settings.sql
--
-- Settings that belong to the deployment, not to any one clinic.
--
-- §21 gives the platform admin "system settings" to configure, and there was
-- nowhere to put them. Everything configurable so far is either a column on
-- `organizations` — which is per-tenant by definition — or an environment
-- variable, which needs a deploy and a person with shell access to change.
--
-- Neither fits a setting that is one value for the whole installation and that
-- an administrator is expected to change from a screen: which payment gateway
-- is in use, whether clinics may sign themselves up, who to email for support.
--
-- Key/value rather than columns, because the list will grow and each new
-- setting should not be a migration. The trade is that nothing here is typed
-- by the database, so the code that reads a value is what gives it a shape.
--
-- What deliberately does NOT live here: API keys and secrets. They stay in the
-- environment. A settings table is read by the panel, dumped in backups, and
-- shown on a screen someone may be sharing — none of which should ever be true
-- of the key that moves money. The panel manages WHICH gateway is used; the
-- deployment holds what proves it is yours.

CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(500) NULL,
    updated_by    BIGINT UNSIGNED NULL,
    updated_at    DATETIME NULL,
    CONSTRAINT fk_platform_setting_user FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
