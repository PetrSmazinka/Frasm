<?php

declare(strict_types=1);

return [
    /*
     * -------------------------------------------------------------------------
     * Enable or Disable Core Authentication Module
     * -------------------------------------------------------------------------
     * When set to false:
     * - Core auth migrations (users, tokens) are omitted.
     * - Routes protected with #[Authorize] will throw a CoreException.
     */
    'enabled' => true,

    /*
     * User provider resolving identities (id + roles) for remember-me logins.
     * Must implement Core\Auth\UserProviderInterface; override to map a custom user model.
     */
    'provider' => \Core\Auth\DatabaseUserProvider::class,

    /*
     * Unauthenticated browser GET requests to protected routes are redirected here
     * (null = respond with 401 instead).
     */
    'login_path' => '/login',

    /*
     * Write an audit log entry (level info) for every request authenticated with an API token.
     */
    'api_audit_log' => true,

    'remember_cookie' => 'frasm_remember',
    'remember_lifetime_days' => 30,

    'default_admin' => [
        'name'        => 'System Administrator',
        'username'    => 'admin',
        'email'       => 'admin@example.com',
        'password'    => 'admin1234',
        'permissions' => 'smarthome.admin,blog.admin',
    ],
];