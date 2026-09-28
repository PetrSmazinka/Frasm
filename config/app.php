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

    // Host names the application answers to, by name. Controllers and PWA apps refer to the names
    // (#[Domain('smarthome')], pwa.apps.<app>.domain), so development can map them to other hosts in
    // local.php (e.g. 'localhost' and 'smarthome.localhost'). A placeholder stands for one label of
    // the host and is passed to the actions: '{tenant}.example.com'. The first host of a name is used
    // for links (url()); literal hosts win over placeholders. Example:
    //   'main'      => ['example.com', 'www.example.com', '192.168.1.10'],
    //   'smarthome' => 'smarthome.example.com',
    //   'tenant'    => '{tenant}.example.com',
    // Once set, requests for any other host (a bare IP address, a forged Host header) get 404:
    // list every host that must keep working. Empty = every host, routes cannot be bound to domains.
    'domains' => [],
];