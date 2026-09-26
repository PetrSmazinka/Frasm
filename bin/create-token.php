<?php

declare(strict_types=1);

/**
 * @file create-token.php
 * @brief Generates a persistent API token for external services.
 * Usage: php bin/create-token.php <service_name> <roles_comma_separated>
 */

require_once dirname(__DIR__) . '/core/Autoload/Autoloader.php';

$autoloader = new \Core\Autoload\Autoloader();
$autoloader->addNamespace('Core', dirname(__DIR__) . '/core');
$autoloader->register();

\Core\Config\Config::load(dirname(__DIR__) . '/config');

$service = $argv[1] ?? null;
$roles = $argv[2] ?? 'api.service';

if (!$service) {
    echo "Usage: php bin/create-token.php <service_name> [roles]\n";
    echo "Example: php bin/create-token.php home-assistant smarthome.admin\n";
    exit(1);
}

$rawToken = bin2hex(random_bytes(32)); // 64-char cryptographically secure token
$tokenHash = hash('sha256', $rawToken);

\Core\DB\DB::getInstance()->insert('api_tokens', [
    'service_name' => $service,
    'token_hash'   => $tokenHash,
    'roles'        => $roles,
]);

echo "✔ Token created for service '{$service}'!\n";
echo "Token: {$rawToken}\n";
echo "Store this token securely; it cannot be recovered from the database.\n";