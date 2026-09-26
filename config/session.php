<?php

declare(strict_types=1);

/**
 * Session configuration
 */
return [
    // Cookie name ('' = PHP default PHPSESSID)
    'name' => '',

    // Cookie lifetime in seconds (0 = until the browser is closed)
    'lifetime' => 0,

    'same_site' => 'Lax',

    // Directory for session files ('' = PHP default, on Debian /var/lib/php/sessions on the SD card).
    // Raspberry Pi: e.g. '/dev/shm/frasm/sessions' (RAM; sessions are lost on reboot, remember-me restores logins).
    'save_path' => '',

    // Idle lifetime of session files when save_path is set (seconds)
    'gc_maxlifetime' => 7200,
];
