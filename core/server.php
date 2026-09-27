<?php

declare(strict_types=1);

/**
 * @file server.php
 * @brief Router script for PHP's built-in development server (`php bin/frasm serve`).
 *
 * Serves existing files from public/ directly and sends every other request to the front
 * controller, mirroring public/.htaccess. Never use the built-in server in production.
 */

$publicDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path !== '/' && is_file($publicDir . $path) && !str_contains($path, '..')) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $publicDir . DIRECTORY_SEPARATOR . 'index.php';
