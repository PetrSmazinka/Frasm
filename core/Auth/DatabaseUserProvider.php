<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\DB\DB;

/**
 * @file DatabaseUserProvider.php
 * @brief Default user provider backed by the core `frasm_users` table.
 */

/**
 * @class DatabaseUserProvider
 * @brief Reads identities from `frasm_users` (created by core/DB/schema.sql) and parses the
 *        comma-separated `permissions` column (MySQL SET or VARCHAR) into roles.
 */
class DatabaseUserProvider implements UserProviderInterface
{
    /**
     * @brief Loads the identity of a user by primary key.
     *
     * @param int|string $id User primary key.
     * @return Identity|null
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function findIdentityById(int|string $id): ?Identity
    {
        $user = DB::getInstance()->selectOne(
            'SELECT `id`, `permissions`, `password_hash` FROM `frasm_users` WHERE `id` = ? LIMIT 1',
            [$id]
        );

        if ($user === null) {
            return null;
        }

        return new Identity(
            (int)$user['id'],
            self::parseRoles((string)$user['permissions']),
            // A digest of the password hash: it changes with the password and reveals nothing about it
            substr(hash('sha256', (string)$user['password_hash']), 0, 32)
        );
    }

    /**
     * @brief Parses a comma-separated role list.
     *
     * @param string $roles Value such as "smarthome.admin,blog.admin".
     * @return list<string>
     */
    public static function parseRoles(string $roles): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $roles)),
            fn(string $role): bool => $role !== ''
        ));
    }
}
