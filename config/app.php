<?php

declare(strict_types=1);

/**
 * General application settings
 */
return [
    'name'     => 'My Frasm App',
    'env'      => 'development',
    'debug'    => false,
    'timezone' => 'Europe/Prague',
    'charset'  => 'UTF-8',
    'locale' => 'cs',
    'fallback_locale' => 'en',

    // Secret for HMAC signatures (live component state, push action tokens). Set it in config/local.php:
    // generate with `php bin/frasm key:generate`.
    'key' => '',

    // Reverse proxies (IPs or CIDR ranges) allowed to set X-Forwarded-For / X-Forwarded-Proto
    'trusted_proxies' => [],
];