<?php

declare(strict_types=1);

/**
 * Scheduler for #[Schedule] tasks
 *
 * When enabled, the installer (install.sh, make update) adds a cron entry running
 * `php bin/frasm schedule:run` every minute; when disabled, it removes the entry.
 * After changing this setting outside of an update, apply it with `php bin/frasm schedule:cron`.
 *
 * Where PHP and cron do not share a system (Docker), set 'runner' to 'daemon': no cron entry is
 * managed, and a supervised `php bin/frasm schedule:work` process starts the runs instead.
 */
return [
    'enabled' => false,

    // What starts schedule:run every minute: 'cron' (entry managed by schedule:cron) or 'daemon' (schedule:work)
    'runner' => 'cron',

    // PHP binary used in the cron entry (null = `php` from PATH, or the PHP running the command)
    'php_binary' => null,
];
