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