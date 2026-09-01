-- Forgotten passwords (§11).
--
-- A short numeric code rather than a signed link: the people who forget a
-- password most often are patients holding a phone, and typing six digits from
-- a message beats following a URL out of an email client and back into an app.
--
-- Only the SHA-256 of the code is stored, for the same reason auth_tokens
-- stores hashes: a leaked table must not hand anyone a working credential.
-- A code is single-use (used_at), short-lived (expires_at) and gives up after
-- a handful of wrong guesses (attempts), which is what stops six digits from
-- being brute-forceable.

CREATE TABLE IF NOT EXISTS password_resets (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    code_hash    CHAR(64) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip_address   VARCHAR(45) NULL,
    created_at   DATETIME NULL,
    updated_at   DATETIME NULL,
    INDEX idx_reset_user (user_id, used_at),
    INDEX idx_reset_expiry (expires_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
