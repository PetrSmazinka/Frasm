<?php

declare(strict_types=1);

namespace Core\Logger;

use Stringable;

/**
 * @file AbstractLogger.php
 * @brief Base logger implementing the per-level shortcut methods on top of log().
 */

/**
 * @class AbstractLogger
 * @brief Delegates every severity shortcut to the abstract log() method.
 */
abstract class AbstractLogger implements LoggerInterface
{
    /**
     * @brief System is unusable.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @brief Action must be taken immediately.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @brief Critical conditions.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @brief Runtime errors that do not require immediate action.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @brief Exceptional occurrences that are not errors.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @brief Normal but significant events.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @brief Interesting events.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @brief Detailed debug information.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
