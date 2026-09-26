<?php

declare(strict_types=1);

/**
 * @file prune-tokens.php
 * @brief Maintenance CLI script to clean up expired remember-me tokens from the database.
 */

define('FRASM_ROOT_DIR', dirname(__DIR__));
define('FRASM_CORE_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core');
define('FRASM_APP_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'app');
define('FRASM_CONFIG_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'config');

require_once FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'Autoloader.php';

$autoloader = new \Core\Autoload\Autoloader();
$autoloader->addNamespace('Core', FRASM_CORE_DIR);
$autoloader->addNamespace('App', FRASM_APP_DIR);
$autoloader->register();

// Load application configuration (database credentials, etc.)
\Core\Config\Config::load(FRASM_CONFIG_DIR);

try {
    $purged = \Core\Auth\Auth::pruneExpiredTokens();
    echo "[" . date('Y-m-d H:i:s') . "] Successfully purged {$purged} expired remember token(s).\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Error pruning tokens: " . $e->getMessage() . "\n");
    exit(1);
}