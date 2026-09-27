<?php

declare(strict_types=1);

/**
 * Logging configuration
 */
return [
    // Directory of daily files <channel>-YYYY-MM-DD.log (must be writable by the web server).
    // Raspberry Pi: point it to tmpfs (e.g. '/dev/shm/frasm/logs') and set 'archive_path' below.
    'path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',

    // Persistent directory filled by `php bin/frasm logs:archive` (cron) when 'path' is volatile; null = off
    'archive_path' => null,

    // Collect entries in memory and write them with one append per request
    'buffered' => true,

    // Minimum level: debug, info, notice, warning, error, critical, alert, emergency
    'level' => 'info',

    'channel' => 'frasm',

    // Days of history kept besides today (0 = never prune)
    'retention_days' => 14,
];
