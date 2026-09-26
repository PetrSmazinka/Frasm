<?php

declare(strict_types=1);

namespace Core\Logger;

use Stringable;

/**
 * @file LoggerInterface.php
 * @brief Logger contract mirroring PSR-3 without the external dependency.
 */

/**
 * @interface LoggerInterface
 * @brief Records messages with severity and structured context.
 *
 * Messages may contain `{placeholder}` tokens replaced by scalar context values.
 * A Throwable passed under the 'exception' context key is serialized with its trace.
 */
interface LoggerInterface
{
    /**
     * @brief System is unusable.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function emergency(string|Stringable $message, array $context = []): void;

    /**
     * @brief Action must be taken immediately.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function alert(string|Stringable $message, array $context = []): void;

    /**
     * @brief Critical conditions.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function critical(string|Stringable $message, array $context = []): void;

    /**
     * @brief Runtime errors that do not require immediate action.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function error(string|Stringable $message, array $context = []): void;

    /**
     * @brief Exceptional occurrences that are not errors (e.g. failed logins, rate limits).
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function warning(string|Stringable $message, array $context = []): void;

    /**
     * @brief Normal but significant events.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function notice(string|Stringable $message, array $context = []): void;

    /**
     * @brief Interesting events (e.g. audit trail of API calls).
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function info(string|Stringable $message, array $context = []): void;

    /**
     * @brief Detailed debug information.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function debug(string|Stringable $message, array $context = []): void;

    /**
     * @brief Logs with an arbitrary level.
     * @param string $level One of the LogLevel constants.
     * @param string|Stringable $message Message.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function log(string $level, string|Stringable $message, array $context = []): void;
}
