<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * @file UserProviderInterface.php
 * @brief Contract decoupling Core\Auth\Auth from the application's user storage and role model.
 */

/**
 * @interface UserProviderInterface
 * @brief Loads identities (id + roles) of users by primary key.
 *
 * The implementation is selected by `auth.provider` in config/auth.php and resolved through the
 * container, so applications can map their own user tables and permission schemes without the
 * core ever importing anything from App\.
 */
interface UserProviderInterface
{
    /**
     * @brief Loads the identity of an active user.
     *
     * @param int|string $id User primary key.
     * @return Identity|null Identity, or null when the user does not exist or must not be authenticated (e.g. disabled).
     */
    public function findIdentityById(int|string $id): ?Identity;
}
