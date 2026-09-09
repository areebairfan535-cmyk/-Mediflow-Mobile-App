-- Where a push notification is actually delivered (§20).
--
-- The notification queue has carried a 'push' channel since the first
-- migration, and PushChannel reported SKIPPED for every one of them —
-- honestly, because there was nowhere to send them. This is that somewhere.
--
-- One row per device per person. A doctor with a phone and a tablet gets two,
-- and both should buzz; the same phone signed in as a different person is a
-- different row, because a notification must never follow the device instead
-- of the account.

CREATE TABLE IF NOT EXISTS `device_tokens` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         BIGINT UNSIGNED NOT NULL,

    -- The Expo push token, e.g. ExponentPushToken[xxxxxxxx]. Kept long
    -- enough for a raw FCM/APNs token too, should this ever stop going
    -- through Expo.
    `token`           VARCHAR(255) NOT NULL,
    `platform`        ENUM('ios','android','web') NOT NULL DEFAULT 'android',

    -- What the person would call it in a "signed-in devices" list.
    `device_name`     VARCHAR(120) DEFAULT NULL,

    -- Touched every time the app re-registers, which it does on each launch.
    -- A token nothing has refreshed for months belongs to an app that was
    -- uninstalled, and pushing to it is how you collect delivery errors.
    `last_seen_at`    DATETIME DEFAULT NULL,

    -- Set when the person signs out, or when the push service tells us the
    -- token is dead. Kept rather than deleted so "was this device ever
    -- registered" stays answerable.
    `revoked_at`      DATETIME DEFAULT NULL,
    `last_error`      VARCHAR(255) DEFAULT NULL,

    `created_at`      DATETIME DEFAULT NULL,
    `updated_at`      DATETIME DEFAULT NULL,

    PRIMARY KEY (`id`),

    -- One live registration per token. Re-registering the same device updates
    -- the row instead of adding a second one, which is what stops a person
    -- receiving four copies of every reminder.
    UNIQUE KEY `uniq_device_token` (`token`),

    KEY `idx_device_user` (`user_id`, `revoked_at`),

    CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
