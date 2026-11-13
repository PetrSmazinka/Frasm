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

    /*
     * Re-read the signed-in user on every request (one primary-key query): role changes apply at once,
     * deleted users are signed out, and a password change ends the user's other sessions.
     */
    'refresh_identity' => true,

    /*
     * Roles that count only together with the access role of their group: group prefix => the role that gives access.
     * ['evidence' => 'evidence.user'] makes every other role starting with "evidence." (evidence.admin, evidence.car…)
     * work only for a user who also has evidence.user; without it the user holds none of them (Auth::roles(),
     * hasRole(), #[Authorize] all see the roles that count). A stateless identity (API token) is not changed.
     */
    'role_requires' => [],

    'remember_cookie' => 'frasm_remember',
    'remember_lifetime_days' => 30,

    'default_admin' => [
        'name'        => 'System Administrator',
        'username'    => 'admin',
        'email'       => 'admin@example.com',
        'password'    => '', // set in config/local.php (the installer generates a random one)
        'permissions' => '',
    ],
];