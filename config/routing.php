<?php

declare(strict_types=1);

/**
 * Routing configuration
 */
return [
    // Application controllers (files ending with 'Controller.php', scanned recursively)
    'controllers_path'      => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers',
    'controllers_namespace' => 'App\\Controllers\\',

    // Compiled route table (skips directory scans and attribute reflection on every request)
    'cache'      => true,
    'cache_path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'routes.php',

    // Re-check controller file timestamps on each request (null = only when app.debug is on).
    // Without validation, run `php bin/routes.php cache` (or `make routes-cache`) after every deploy.
    'cache_validate' => null,
];
