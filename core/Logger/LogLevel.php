<?php

declare(strict_types=1);

namespace Core\Logger;

/**
 * @file LogLevel.php
 * @brief Log severity levels (RFC 5424, PSR-3 compatible names).
 */

/**
 * @class LogLevel
 * @brief Severity constants and their numeric priorities.
 */
final class LogLevel
{
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    /**
     * @var array<string, int> Level name => priority (higher is more severe).
     */
    public const PRIORITIES = [
        self::DEBUG     => 100,
        self::INFO      => 200,
        self::NOTICE    => 250,
        self::WARNING   => 300,
        self::ERROR     => 400,
        self::CRITICAL  => 500,
        self::ALERT     => 550,
        self::EMERGENCY => 600,
    ];

    /**
     * @brief Prevents instantiation of the constants holder.
     */
    private function __construct()
    {
    }

    /**
     * @brief Checks whether a level name is valid.
     *
     * @param string $level Level name.
     * @return bool
     */
    public static function isValid(string $level): bool
    {
        return isset(self::PRIORITIES[$level]);
    }
}
