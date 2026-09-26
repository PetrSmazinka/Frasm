-- =============================================================================
-- Frasm core database schema
-- -----------------------------------------------------------------------------
-- Applied idempotently by Core\DB\Migrator before application migrations
-- (bin/db.php migrate). Every internal table uses the `frasm_` prefix.
--
-- Sections introduced by "-- @module <name>" are applied only when the module is
-- enabled (see Migrator::CORE_MODULES). Statements must end with ';' at line end.
-- Existing tables are never altered: during development use `make reset`.
-- =============================================================================

-- @module core

CREATE TABLE IF NOT EXISTS `frasm_rate_limits` (
    `key_hash` CHAR(64) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`key_hash`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- @module auth

CREATE TABLE IF NOT EXISTS `frasm_users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `username` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `permissions` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Comma-separated role names',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    UNIQUE KEY `uniq_email` (`email`)
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

-- @module push

CREATE TABLE IF NOT EXISTS `frasm_push_subscriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NULL,
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
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
