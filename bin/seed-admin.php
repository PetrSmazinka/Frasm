<?php

declare(strict_types=1);

/**
 * @file seed-admin.php
 * @brief CLI utility to seed or synchronize the default administrator account from configuration.
 */

use Core\Config\Config;
use Core\DB\DB;

// Shared bootstrap is idempotent, so this script also works when included by bin/db.php
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

// 1. Guard against disabled Auth module
if (!(bool)Config::get('auth.enabled', true)) {
    echo "Notice: Auth module is disabled (auth.enabled = false). Skipping admin seeding.\n";
    return;
}

// 2. Fetch admin credentials from configuration
/** @var array{name?: string, username?: string, email?: string, password?: string, permissions?: string} $adminConfig */
$adminConfig = (array)Config::get('auth.default_admin', []);

if (empty($adminConfig['username']) || empty($adminConfig['password']) || empty($adminConfig['email'])) {
    fwrite(STDERR, "Error: Default admin configuration not found or incomplete in config/auth.php\n");
    return;
}

try {
    $db = DB::getInstance();

    // 3. Check if user already exists by username or email
    $existingUser =$db->selectOne(
        'SELECT `id`, `username` FROM `frasm_users` WHERE `username` = ? OR `email` = ? LIMIT 1',
        [$adminConfig['username'],$adminConfig['email']]
    );

    $passwordHash = password_hash((string)$adminConfig['password'], PASSWORD_DEFAULT);
    $name = (string)($adminConfig['name'] ?? 'System Administrator');
    $permissions = (string)($adminConfig['permissions'] ?? '');

    if ($existingUser) {$db->update(
            'frasm_users',
            [
                'name'          => $name,
                'password_hash' => $passwordHash,
                'permissions'   => $permissions,
            ],
            '`id` = ?',
            [$existingUser['id']]
        );
        echo "✔ Default administrator '{$adminConfig['username']}' updated successfully.\n";
    } else {
        $db->insert('frasm_users', [
            'name'          => $name,
            'email'         => (string)$adminConfig['email'],
            'username'      => (string)$adminConfig['username'],
            'password_hash' => $passwordHash,
            'permissions'   => $permissions,
        ]);
        echo "✔ Default administrator '{$adminConfig['username']}' created successfully.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error seeding admin: " . $e->getMessage() . "\n");
}