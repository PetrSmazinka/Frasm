-- =============================================================================
-- Frasm core database schema
-- -----------------------------------------------------------------------------
-- Applied idempotently by Core\DB\Migrator before application migrations
-- (php bin/frasm db:migrate). Every internal table uses the `frasm_` prefix.
--
-- Sections introduced by "-- @module <name>" are applied only when the module is
-- enabled (see Migrator::CORE_MODULES). Statements must end with ';' at line end.
-- Existing tables are altered only by statements preceded by "-- @unless-column <table>.<column>"
-- (run when the column is missing) or "-- @unless-type <table>.<column> <type>" (run while the column has another
-- type); otherwise use `php bin/frasm db:reset` during development.
-- =============================================================================

-- @module core

CREATE TABLE IF NOT EXISTS `frasm_rate_limits` (
    `key_hash` CHAR(64) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`key_hash`),
    KEY `idx_expires_at` (`expires_at`) USING BTREE
) ENGINE=MEMORY DEFAULT CHARSET=ascii COLLATE=ascii_bin COMMENT='Volatile by design: no disk writes (SD card), counters reset on DB restart';

CREATE TABLE IF NOT EXISTS `frasm_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
    `job_class` VARCHAR(255) NOT NULL,
    `payload` MEDIUMTEXT NOT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    `available_at` DATETIME NOT NULL,
    `reserved_at` DATETIME NULL,
    `failed_at` DATETIME NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_queue_pick` (`queue`, `failed_at`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `frasm_scheduled_tasks` (
    `task` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `locked_until` DATETIME NULL,
    `last_started_at` DATETIME NULL,
    `last_finished_at` DATETIME NULL,
    `last_status` VARCHAR(16) NULL,
    `last_error` TEXT NULL,
    `last_duration_ms` INT UNSIGNED NULL,
    `last_trigger` VARCHAR(16) NULL,
    `last_success_at` DATETIME NULL COMMENT 'End of the last successful run (alerts)',
    `alerted_at` DATETIME NULL COMMENT 'An alert went out for the current failures (cleared by a success)',
    PRIMARY KEY (`task`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @unless-column frasm_scheduled_tasks.last_success_at
ALTER TABLE `frasm_scheduled_tasks`
    ADD COLUMN `last_success_at` DATETIME NULL COMMENT 'End of the last successful run (alerts)' AFTER `last_trigger`,
    ADD COLUMN `alerted_at` DATETIME NULL COMMENT 'An alert went out for the current failures (cleared by a success)' AFTER `last_success_at`;

-- @module auth

CREATE TABLE IF NOT EXISTS `frasm_users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `username` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `permissions` TEXT NOT NULL DEFAULT '' COMMENT 'Comma-separated role names',
    `expires_at` DATETIME NULL COMMENT 'The account stops working at this time (e.g. guests); NULL = never',
    `external_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SSO client: user ID at the SSO server',
    `synced_at` DATETIME NULL COMMENT 'SSO client: last refresh from the SSO server',
    `created_by_client` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SSO server: client that created the account and may manage it',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    UNIQUE KEY `uniq_email` (`email`),
    UNIQUE KEY `uniq_external_id` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles of an administrator of many applications outgrew 1000 characters
-- @unless-type frasm_users.permissions text
ALTER TABLE `frasm_users`
    MODIFY `permissions` TEXT NOT NULL DEFAULT '' COMMENT 'Comma-separated role names';

-- @unless-column frasm_users.expires_at
ALTER TABLE `frasm_users`
    ADD COLUMN `expires_at` DATETIME NULL COMMENT 'The account stops working at this time (e.g. guests); NULL = never' AFTER `permissions`;

-- @unless-column frasm_users.external_id
ALTER TABLE `frasm_users`
    ADD COLUMN `external_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SSO client: user ID at the SSO server' AFTER `expires_at`,
    ADD COLUMN `synced_at` DATETIME NULL COMMENT 'SSO client: last refresh from the SSO server' AFTER `external_id`,
    ADD UNIQUE KEY `uniq_external_id` (`external_id`);

-- @unless-column frasm_users.created_by_client
ALTER TABLE `frasm_users`
    ADD COLUMN `created_by_client` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'SSO server: client that created the account and may manage it' AFTER `synced_at`;

-- @unless-column frasm_users.phone
ALTER TABLE `frasm_users`
    ADD COLUMN `phone` VARCHAR(32) NULL COMMENT 'Phone number of the account (passed to SSO clients)' AFTER `email`,
    ADD COLUMN `avatar` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'Version of the profile photo (Core\\Auth\\Avatars); NULL = none' AFTER `phone`;

CREATE TABLE IF NOT EXISTS `frasm_password_resets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii NOT NULL COMMENT 'SHA-256 of the link token (the token itself is only in the e-mail)',
    `client` VARCHAR(64) CHARACTER SET ascii NULL COMMENT 'SSO client that asked for it (NULL = this application)',
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_password_reset_token` (`token_hash`),
    KEY `idx_password_reset_user` (`user_id`),
    CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `frasm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `frasm_passkeys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `credential_id` VARBINARY(1023) NOT NULL COMMENT 'WebAuthn credential ID',
    `user_handle` BINARY(32) NOT NULL COMMENT 'Random handle of the user (the same for all their passkeys)',
    `public_key` TEXT NOT NULL COMMENT 'PEM',
    `algorithm` SMALLINT NOT NULL COMMENT 'COSE algorithm (-7 ES256, -257 RS256)',
    `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `transports` VARCHAR(100) CHARACTER SET ascii NOT NULL DEFAULT '',
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL,
    `last_used_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_passkey_credential` (`credential_id`(255)),
    KEY `idx_passkey_user` (`user_id`),
    CONSTRAINT `fk_passkey_user` FOREIGN KEY (`user_id`) REFERENCES `frasm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `frasm_remember_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `selector` VARCHAR(32) NOT NULL,
    `validator_hash` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_selector` (`selector`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_frasm_remember_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `frasm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `frasm_api_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `service_name` VARCHAR(100) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `roles` VARCHAR(255) NOT NULL DEFAULT '',
    `last_used_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @module sso

CREATE TABLE IF NOT EXISTS `frasm_sso_codes` (
    `code_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 of the one-time authorization code',
    `client` VARCHAR(100) NOT NULL COMMENT 'Service name of the client API token',
    `user_id` INT(11) NOT NULL,
    `redirect_uri` VARCHAR(500) NOT NULL,
    `challenge` CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'PKCE S256 code challenge',
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`code_hash`),
    KEY `idx_expires_at` (`expires_at`),
    CONSTRAINT `fk_frasm_sso_codes_user` FOREIGN KEY (`user_id`) REFERENCES `frasm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @module push

CREATE TABLE IF NOT EXISTS `frasm_push_subscriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NULL,
    `app` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'PWA app (pwa.apps key) the subscription belongs to, "" = main app',
    `endpoint` VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `endpoint_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `p256dh` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `auth` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_success_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_endpoint_hash` (`endpoint_hash`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_app` (`app`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @unless-column frasm_push_subscriptions.app
ALTER TABLE `frasm_push_subscriptions`
    ADD COLUMN `app` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'PWA app (pwa.apps key) the subscription belongs to, "" = main app' AFTER `user_id`,
    ADD KEY `idx_app` (`app`);

CREATE TABLE IF NOT EXISTS `frasm_push_channels` (
    `subscription_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`subscription_id`, `channel`),
    KEY `idx_channel` (`channel`, `subscription_id`),
    CONSTRAINT `fk_frasm_push_channels_subscription` FOREIGN KEY (`subscription_id`)
        REFERENCES `frasm_push_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
