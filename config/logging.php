<?php

declare(strict_types=1);

/**
 * Logging configuration
 */
return [
    // Directory of daily files <channel>-YYYY-MM-DD.log (must be writable by the web server)
    'path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',

    // Minimum level: debug, info, notice, warning, error, critical, alert, emergency
    'level' => 'info',

    'channel' => 'frasm',

    // Days of history kept besides today (0 = never prune)
    'retention_days' => 14,
];
