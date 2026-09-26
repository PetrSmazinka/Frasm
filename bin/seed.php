<?php

declare(strict_types=1);

/**
 * @file seed-admin.php
 * @brief CLI utility to seed or synchronize the default administrator account from configuration.
 */

define('FRASM_ROOT_DIR', dirname(__DIR__));
define('FRASM_CORE_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core');
define('FRASM_APP_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'app');
define('FRASM_CONFIG_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'config');

require_once FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'Autoloader.php';

$autoloader = new \Core\Autoload\Autoloader();$autoloader->addNamespace('Core', FRASM_CORE_DIR);
$autoloader->addNamespace('App', FRASM_APP_DIR);$autoloader->register();

\Core\Config\Config::load(FRASM_CONFIG_DIR);

$adminConfig = (array)\Core\Config\Config::get('auth.default_admin', []);
if (empty($adminConfig['username']) || empty($adminConfig['password'])) {
    fwrite(STDERR, "Error: Default admin configuration not found in config/auth.php\n");
    exit(1);
}

$db = \Core\DB\DB::getInstance();

$existingUser =$db->selectOne(
    'SELECT id, username FROM `frasm_users` WHERE `username` = ? OR `email` = ? LIMIT 1',
    [$adminConfig['username'],$adminConfig['email']]
);

$passwordHash = password_hash((string)$adminConfig['password'], PASSWORD_DEFAULT);

if ($existingUser) {$db->update(
        'frasm_users',
        [
            'name'          => $adminConfig['name'],
            'password_hash' => $passwordHash,
            'permissions'   => $adminConfig['permissions'],
        ],
        'id = ?',
        [$existingUser['id']]
    );
    echo "✔ Default administrator '{$adminConfig['username']}' updated successfully.\n";
} else {
    $db->insert('frasm_users', [
        'name'          => $adminConfig['name'],
        'email'         => $adminConfig['email'],
        'username'      => $adminConfig['username'],
        'password_hash' => $passwordHash,
        'permissions'   => $adminConfig['permissions'],
    ]);
    echo "✔ Default administrator '{$adminConfig['username']}' created successfully.\n";
}