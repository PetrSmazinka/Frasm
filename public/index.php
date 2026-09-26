<?php

declare(strict_types=1);

/**
 * @file index.php
 * @brief Universal public web entry point (Front Controller) for the Frasm framework.
 *
 * Bootstraps the autoloader, applies debug and error-reporting directives from Config,
 * initializes session and localization, dynamically registers application controllers,
 * and dispatches the HTTP request.
 */

use Core\Autoload\Autoloader;
use Core\Config\Config;
use Core\Exceptions\AuthException;
use Core\Exceptions\CoreException;
use Core\Exceptions\FrasmException;
use Core\Exceptions\MethodNotAllowedException;
use Core\Exceptions\RouteNotFoundException;
use Core\I18n\Lang;
use Core\Routing\Router;
use Core\Session\Session;
use Core\View\Live\LiveComponentHandler;

/**
 * Root directory definitions.
 */
define('FRASM_ROOT_DIR', dirname(__DIR__));
define('FRASM_CORE_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core');
define('FRASM_APP_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'app');
define('FRASM_CONFIG_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'config');

/*
 * -----------------------------------------------------------------------------
 * 1. Register PSR-4 Autoloader & Core Global Helpers
 * -----------------------------------------------------------------------------
 */
require_once FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('Core', FRASM_CORE_DIR);
$autoloader->addNamespace('App', FRASM_APP_DIR);
$autoloader->register();

// Load core global helper functions (e.g., __() for i18n)
$helpersPath = FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Helpers' . DIRECTORY_SEPARATOR . 'helpers.php';
if (file_exists($helpersPath)) {
    require_once $helpersPath;
}

/*
 * -----------------------------------------------------------------------------
 * 2. Boot Configuration & Runtime Environment
 * -----------------------------------------------------------------------------
 */
try {
    Config::load(FRASM_CONFIG_DIR);
} catch (\Throwable $e) {
    http_response_code(500);
    echo "<h1>Bootstrap Error</h1><p>Failed to load configuration files: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit(1);
}

$isDebug = (bool)Config::get('app.debug', false);

// Enforce PHP runtime directives matching debug state
if ($isDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

// Convert native PHP notices, warnings and errors into catchable ErrorExceptions
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Configure default timezone from configuration
date_default_timezone_set((string)Config::get('app.timezone', 'UTC'));

/*
 * -----------------------------------------------------------------------------
 * 3. Execution Pipeline & Global Error Handling
 * -----------------------------------------------------------------------------
 */
try {
    // Securely start or resume session via the Session service
    Session::start();

    // Auto-login from persistent remember-me cookie (only if Auth module is enabled)
    $authEnabled = (bool)Config::get('auth.enabled', true);
    if ($authEnabled && !\Core\Auth\Auth::check()) {
        \Core\Auth\Auth::attemptRememberLogin();
    }

    // Boot localization service
    Lang::boot();
    $router = new Router();

    // 1. Register framework internal core handlers
    $router->registerController(LiveComponentHandler::class);

    // 2. Auto-discover application controllers from configured path
    $controllersPath = (string)Config::get(
        'routing.controllers_path',
        FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Controllers'
    );
    $controllersNamespace = (string)Config::get('routing.controllers_namespace', 'App\\Controllers\\');

    $router->registerControllersFromDirectory($controllersPath, $controllersNamespace);

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

    $response = $router->dispatch($requestMethod, $requestUri);

    if (is_array($response) || is_object($response)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else {
        echo (string)$response;
    }

} catch (\Throwable $e) {
    // Determine proper HTTP status code
    $statusCode = 500;
    if ($e instanceof RouteNotFoundException) {
        $statusCode = 404;
    } elseif ($e instanceof MethodNotAllowedException) {
        $statusCode = 405;
    } elseif ($e instanceof AuthException) {
        $statusCode = ($e->getCode() >= 400 && $e->getCode() < 500) ? $e->getCode() : 401;
    } elseif ($e->getCode() >= 400 && $e->getCode() < 600) {
        $statusCode = $e->getCode();
    }

    http_response_code($statusCode);

    // Detect if client expects JSON
    $isJsonExpected = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if ($isJsonExpected) {
        header('Content-Type: application/json; charset=UTF-8');
        $payload = [
            'status'  => $statusCode,
            'message' => $isDebug ? $e->getMessage() : ($statusCode === 404 ? 'Not Found' : 'Internal Server Error'),
        ];

        if ($isDebug) {
            $payload['exception'] = get_class($e);
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = explode("\n", $e->getTraceAsString());
            if ($e instanceof FrasmException) {
                $payload['context'] = $e->getContext();
            }
        }

        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // HTML Output rendering
    if ($isDebug) {
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug Exception</title>";
        echo "<style>body{font-family:monospace;background:#1e1e2e;color:#cdd6f4;padding:2rem;}";
        echo "h1{color:#f38ba8;margin-bottom:0.5rem;} h2{color:#fab387;margin-top:0;}";
        echo "pre{background:#11111b;padding:1rem;border-radius:6px;overflow-x:auto;color:#a6adc8;}";
        echo ".context{background:#181825;padding:1rem;margin:1rem 0;border-left:4px solid #89b4fa;}</style></head><body>";
        echo "<h1>" . htmlspecialchars(get_class($e)) . " (HTTP " . $statusCode . ")</h1>";
        echo "<h2>" . htmlspecialchars($e->getMessage()) . "</h2>";
        echo "<p><strong>Location:</strong> " . htmlspecialchars($e->getFile()) . " on line <strong>" . $e->getLine() . "</strong></p>";

        if ($e instanceof FrasmException && !empty($e->getContext())) {
            echo "<div class='context'><h3>Diagnostic Context:</h3><pre>" . htmlspecialchars(json_encode($e->getContext(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre></div>";
        }

        echo "<h3>Call Stack Trace:</h3>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</body></html>";
    } else {
        // Clean production page without leaking sensitive data
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Error</title></head>";
        echo "<body style='font-family:sans-serif;text-align:center;padding:5rem;'>";
        if ($statusCode === 404) {
            echo "<h1>404 - Page Not Found</h1><p>The requested URL was not found on this server.</p>";
        } else {
            echo "<h1>500 - Server Error</h1><p>Something went wrong. Please try again later.</p>";
        }
        echo "</body></html>";
    }
}