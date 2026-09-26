<?php

declare(strict_types=1);

/**
 * @file logs.php
 * @brief Moves logs from a volatile (tmpfs) log directory to persistent storage.
 *
 * Usage: php bin/logs.php archive   (e.g. hourly from cron when logging.path is on tmpfs)
 */

use Core\Container\Container;
use Core\Logger\Logger;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (($argv[1] ?? '') !== 'archive') {
    echo "Usage: php bin/logs.php archive\n";
    exit(0);
}

if (\Core\Config\Config::get('logging.archive_path') === null) {
    echo "Nothing to do: logging.archive_path is not configured.\n";
    exit(0);
}

$bytes = Container::getInstance()->get(Logger::class)->archive();
echo "[" . date('Y-m-d H:i:s') . "] Archived {$bytes} bytes of logs.\n";
