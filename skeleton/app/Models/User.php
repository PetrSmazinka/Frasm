<?php

declare(strict_types=1);

namespace App\Models;

use Core\DB\DB;

/**
 * @file User.php
 * @brief User domain model managing credential lookup and permission set parsing via Core\DB\DB.
 */
class User
{
    /**
     * @brief Finds a user record matching either username or email.
     *
     * @param string $identifier Username or email address.
     * @return array<string, mixed>|null Associative user record or null if not found.
     */
    public static function findByIdentifier(string $identifier): ?array
    {
        $db = DB::getInstance();

        $query = 'SELECT id, name, email, username, password_hash, permissions 
                  FROM `frasm_users` 
                  WHERE `username` = ? OR `email` = ? 
                  LIMIT 1';

        return $db->selectOne($query, [$identifier,$identifier]);
    }

    /**
     * @brief Parses a MySQL SET column into an indexed list of permission strings.
     *
     * Converts comma-delimited SET strings (e.g. "smarthome.admin,blog.admin")
     * into a normalized array of distinct roles.
     *
     * @param string $permissionsSet Comma-separated permissions from the database.
     * @return list<string> Normalized list of role strings.
     */
    public static function parsePermissions(string $permissionsSet): array
    {
        if (trim($permissionsSet) === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', $permissionsSet),
            fn(string $role): bool => trim($role) !== ''
        ));
    }
}