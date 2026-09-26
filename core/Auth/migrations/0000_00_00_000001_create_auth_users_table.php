<?php

declare(strict_types=1);

use Core\DB\DB;

return new class {
    public function up(): void
    {
        DB::getInstance()->query("
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `username` VARCHAR(255) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `permissions` SET('smarthome.admin', 'smarthome.user', 'blog.admin', '') NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_username` (`username`),
                UNIQUE KEY `uniq_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        DB::getInstance()->query("DROP TABLE IF EXISTS `users`;");
    }
};