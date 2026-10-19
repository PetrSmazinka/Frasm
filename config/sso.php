<?php

declare(strict_types=1);

/**
 * Single sign-on between Frasm applications on different domains (Core\Auth\Sso)
 *
 * Server (the application owning the accounts):
 *   1) 'server.enabled' => true and a client entry below, 2) `php bin/frasm db:migrate` (frasm_sso_codes),
 *   3) `php bin/frasm token:create <client> --roles=sso.client` – give the token to the client.
 *   Users get access by roles with the client's prefix, e.g. 'band.member' (the client sees 'member').
 *   The login page must send the user to its `return` parameter (Auth::safeReturnPath()).
 * Client (the application signing users in): 'client.enabled' => true, the server URL and the token,
 *   'auth.provider' => \Core\Auth\Sso\SsoUserProvider::class; the login page offers /_frasm/sso/login
 *   and may check passwords with SsoClient::passwordLogin().
 */
return [
    'server' => [
        'enabled' => false,

        // Clients: service name of their API token => settings
        'clients' => [
            // 'band' => [
            //     'name'          => 'Band',
            //     'redirect_uris' => ['https://band.example.com/_frasm/sso/callback'],
            //     'role_prefix'   => 'band.',
            // ],
        ],

        // Seconds a one-time authorization code is valid
        'code_ttl' => 60,
    ],

    'client' => [
        'enabled' => false,

        // URL of the SSO server (https://…), the client name there and its API token (keep it in config/local.php)
        'server' => '',
        'client' => '',
        'token'  => '',

        // Callback registered at the server: https://<main host of this application>/_frasm/sso/callback
        'redirect_uri' => '',

        // Label of the sign-in button
        'name' => 'SSO',

        // Seconds a signed-in user is trusted before being re-read from the server (role changes, removal)
        'refresh' => 300,

        // Keep users signed in after a redirect login (remember-me cookie)
        'remember' => true,

        // Seconds to wait for the server
        'timeout' => 10,
    ],
];
