<?php

declare(strict_types=1);

/**
 * @file create-token.php
 * @brief Generates a persistent API token for external services.
 *
 * Usage: php bin/create-token.php <service_name> [roles_comma_separated] [expires_in_days]
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$service = $argv[1] ?? null;
$roles = $argv[2] ?? 'api.service';
$expiresInDays = isset($argv[3]) ? filter_var($argv[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;

if (!$service || !preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $service) || $expiresInDays === false) {
    echo "Usage: php bin/create-token.php <service_name> [roles] [expires_in_days]\n";
    echo "Example: php bin/create-token.php home-assistant smarthome.admin 365\n";
    exit(1);
}

$rawToken = bin2hex(random_bytes(32)); // 64-char cryptographically secure token
$tokenHash = hash('sha256', $rawToken);

\Core\DB\DB::getInstance()->query(
    'INSERT INTO `frasm_api_tokens` (`service_name`, `token_hash`, `roles`, `expires_at`)
     VALUES (?, ?, ?, IF(? IS NULL, NULL, NOW() + INTERVAL ? DAY))',
    [$service, $tokenHash, $roles, $expiresInDays, $expiresInDays]
);

echo "✔ Token created for service '{$service}'!\n";
echo "Token: {$rawToken}\n";
echo "Store this token securely; it cannot be recovered from the database.\n";