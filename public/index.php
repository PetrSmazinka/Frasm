<?php

declare(strict_types=1);

/**
 * @file index.php
 * @brief Universal public web entry point (Front Controller) for the Frasm framework.
 *
 * Bootstraps the framework (autoloader, configuration, container), installs the central error
 * handler, captures the HTTP request and lets the HTTP kernel turn it into a response.
 */

use Core\Error\ErrorHandler;
use Core\Http\Kernel;
use Core\Http\Request;

define('FRASM_ROOT_DIR', dirname(__DIR__));

/*
 * Application assets (app/Assets, URL /assets/...) are served before the framework boots:
 * no session, routing or database work for a stylesheet or a script.
 */
require FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'AssetServer.php';
if (\Core\Http\AssetServer::handle($_SERVER, FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Assets')) {
    return;
}

/*
 * -----------------------------------------------------------------------------
 * 1. Bootstrap (autoloader, configuration, service container)
 * -----------------------------------------------------------------------------
 */
try {
    $container = require_once FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
} catch (\Throwable $e) {
    // Configuration is unavailable, so the debug flag is unknown: never expose details here
    error_log('[Frasm] Bootstrap failure: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Server Error</title></head>'
        . '<body><h1>500 - Server Error</h1><p>The application failed to start.</p></body></html>';
    exit(1);
}

/*
 * -----------------------------------------------------------------------------
 * 2. Central error & exception handling
 * -----------------------------------------------------------------------------
 */
$container->get(ErrorHandler::class)->register();

/*
 * -----------------------------------------------------------------------------
 * 3. Request → Kernel → Response
 * -----------------------------------------------------------------------------
 */
$request = Request::fromGlobals();

/** @var Kernel $kernel */
$kernel = $container->get(Kernel::class);
$response = $kernel->handle($request);
$kernel->send($request, $response);
