<?php

declare(strict_types=1);

use Core\DB\DB;

/**
 * @file 0000_00_00_000003_create_auth_api_tokens_table.php
 * @brief Core migration creating the API tokens storage table for external services.
 */
return new class {
    public function up(): void
    {
        $db = DB::getInstance();

        $db->query("
            CREATE TABLE IF NOT EXISTS `api_tokens` (
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
        ");
    }

    public function down(): void
    {
        DB::getInstance()->query("DROP TABLE IF EXISTS `api_tokens`;");
    }
};