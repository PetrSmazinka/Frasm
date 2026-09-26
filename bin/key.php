<?php

declare(strict_types=1);

/**
 * @file key.php
 * @brief Generates a new application key (app.key) used for HMAC signatures.
 *
 * Usage: php bin/key.php
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$key = \Core\Security\Signer::generateKey();

echo "Add to config/local.php (keep it secret, never commit it):\n\n";
echo "    'app' => [\n";
echo "        'key' => '{$key}',\n";
echo "    ],\n\n";
echo "Changing the key invalidates rendered live components and pending push action tokens.\n";
