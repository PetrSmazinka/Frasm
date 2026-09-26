<?php

declare(strict_types=1);

use Core\DB\DB;

/**
 * @file 0000_00_00_000002_create_auth_remember_tokens_table.php
 * @brief Core migration creating the persistent remember-me tokens storage table.
 */
return new class {
    /**
     * @brief Creates the user_remember_tokens table with constraints.
     */
    public function up(): void
    {
        $db = DB::getInstance();

        $db->query("
            CREATE TABLE IF NOT EXISTS `user_remember_tokens` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `selector` VARCHAR(32) NOT NULL,
                `validator_hash` VARCHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_selector` (`selector`),
                KEY `idx_user_id` (`user_id`),
                CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * @brief Drops the user_remember_tokens table on rollback.
     */
    public function down(): void
    {
        $db = DB::getInstance();

        $db->query("DROP TABLE IF EXISTS `user_remember_tokens`;");
    }
};