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

    // Share the login with all subdomains: the parent domain, e.g. 'example.com' ('' = this host only).
    // Every subdomain then receives the cookies, including other applications running there: give
    // this one its own cookie names (session.name, auth.remember_cookie) so they do not collide,
    // which also makes existing host-only cookies irrelevant after the change.
    'domain' => '',

    // Directory for session files ('' = PHP default, on Debian /var/lib/php/sessions on the SD card).
    // Raspberry Pi: e.g. '/dev/shm/frasm/sessions' (RAM; sessions are lost on reboot, remember-me restores logins).
    'save_path' => '',

    // Idle lifetime of session files when save_path is set (seconds)
    'gc_maxlifetime' => 7200,
];
