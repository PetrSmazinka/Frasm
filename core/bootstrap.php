<?php

declare(strict_types=1);

/**
 * @file bootstrap.php
 * @brief Shared framework bootstrap for the web front controller and CLI scripts.
 *
 * Defines path constants, registers the PSR-4 autoloader and global helpers, loads configuration,
 * sets the timezone and registers core services in the DI container. When `app/bootstrap.php`
 * exists and returns a callable, it is invoked with the container to register application bindings.
 * Include with require_once; the container is also available via Container::getInstance().
 *
 * @return \Core\Container\Container
 * @throws \Throwable When configuration cannot be loaded.
 */

use Core\Auth\DatabaseUserProvider;
use Core\Auth\UserProviderInterface;
use Core\Autoload\Autoloader;
use Core\Config\Config;
use Core\Container\Container;
use Core\Error\ErrorHandler;
use Core\Http\Kernel;
use Core\Http\Middleware\MiddlewareResolver;
use Core\Logger\Logger;
use Core\Logger\LoggerInterface;
use Core\Logger\LogLevel;
use Core\RateLimit\RateLimiter;
use Core\Routing\Router;

defined('FRASM_ROOT_DIR') || define('FRASM_ROOT_DIR', dirname(__DIR__));
defined('FRASM_CORE_DIR') || define('FRASM_CORE_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core');
defined('FRASM_APP_DIR') || define('FRASM_APP_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'app');
defined('FRASM_CONFIG_DIR') || define('FRASM_CONFIG_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'config');
defined('FRASM_STORAGE_DIR') || define('FRASM_STORAGE_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'storage');

/*
 * -----------------------------------------------------------------------------
 * 1. PSR-4 Autoloader & Core Global Helpers
 * -----------------------------------------------------------------------------
 */
require_once FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('Core', FRASM_CORE_DIR);
$autoloader->addNamespace('App', FRASM_APP_DIR);
$autoloader->register();

require_once FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Helpers' . DIRECTORY_SEPARATOR . 'helpers.php';

/*
 * -----------------------------------------------------------------------------
 * 2. Configuration & Runtime Environment
 * -----------------------------------------------------------------------------
 */
Config::load(FRASM_CONFIG_DIR);
date_default_timezone_set((string)Config::get('app.timezone', 'UTC'));

/*
 * -----------------------------------------------------------------------------
 * 3. Core Service Bindings
 * -----------------------------------------------------------------------------
 */
$container = Container::getInstance();

$container->singleton(LoggerInterface::class, function (): LoggerInterface {
    $level = (string)Config::get('logging.level', LogLevel::DEBUG);

    return new Logger(
        (string)Config::get('logging.path', FRASM_STORAGE_DIR . DIRECTORY_SEPARATOR . 'logs'),
        LogLevel::isValid($level) ? $level : LogLevel::DEBUG,
        (string)Config::get('logging.channel', 'frasm'),
        (int)Config::get('logging.retention_days', 14)
    );
});
$container->singleton(Logger::class, fn(Container $c): LoggerInterface => $c->get(LoggerInterface::class));

$container->singleton(UserProviderInterface::class, function (Container $c): UserProviderInterface {
    $class = (string)Config::get('auth.provider', DatabaseUserProvider::class);
    $provider = $c->get($class);

    if (!$provider instanceof UserProviderInterface) {
        throw new \Core\Exceptions\CoreException("Configured auth.provider '{$class}' must implement " . UserProviderInterface::class . '.');
    }

    return $provider;
});

$container->singleton(RateLimiter::class, fn(): RateLimiter => new RateLimiter(
    (int)Config::get('middleware.rate_limit_prune_probability', 100)
));
$container->singleton(Router::class);
$container->singleton(MiddlewareResolver::class);
$container->singleton(ErrorHandler::class);
$container->singleton(Kernel::class);

/*
 * -----------------------------------------------------------------------------
 * 4. Application Bindings (optional app/bootstrap.php returning callable(Container): void)
 * -----------------------------------------------------------------------------
 */
$appBootstrap = FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'bootstrap.php';
if (is_file($appBootstrap)) {
    $register = require $appBootstrap;
    if (is_callable($register)) {
        $register($container);
    }
}

return $container;
