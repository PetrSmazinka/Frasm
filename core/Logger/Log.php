<?php

declare(strict_types=1);

namespace Core\Logger;

use Core\Container\Container;
use Stringable;
use Throwable;

/**
 * @file Log.php
 * @brief Static facade over the container-bound LoggerInterface.
 */

/**
 * @class Log
 * @brief Convenience access to the application logger from static contexts (e.g. Core\Auth\Auth).
 *
 * Prefer constructor injection of LoggerInterface in services and controllers. When no logger
 * is bound (e.g. very early bootstrap failure), entries fall back to PHP's error_log().
 */
final class Log
{
    /**
     * @brief Prevents instantiation of the facade.
     */
    private function __construct()
    {
    }

    /**
     * @brief Logs a critical condition.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public static function critical(string|Stringable $message, array $context = []): void
    {
        self::write(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @brief Logs a runtime error.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public static function error(string|Stringable $message, array $context = []): void
    {
        self::write(LogLevel::ERROR, $message, $context);
    }

    /**
     * @brief Logs a warning (e.g. failed login, rate limit hit).
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public static function warning(string|Stringable $message, array $context = []): void
    {
        self::write(LogLevel::WARNING, $message, $context);
    }

    /**
     * @brief Logs a normal but significant event.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public static function notice(string|Stringable $message, array $context = []): void
    {
        self::write(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @brief Logs an informational event (e.g. API audit trail).
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public static function info(string|Stringable $message, array $context = []): void
    {
        self::write(LogLevel::INFO, $message, $context);
    }

    /**
     * @brief Logs debug information.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public static function debug(string|Stringable $message, array $context = []): void
    {
        self::write(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @brief Resolves the logger and records the entry, never throwing.
     *
     * @param string $level Level name.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    private static function write(string $level, string|Stringable $message, array $context): void
    {
        try {
            $container = Container::getInstance();
            if ($container->bound(LoggerInterface::class)) {
                $container->get(LoggerInterface::class)->log($level, $message, $context);
                return;
            }
        } catch (Throwable) {
            // Fall through to error_log()
        }

        error_log('[' . strtoupper($level) . '] ' . $message);
    }
}
