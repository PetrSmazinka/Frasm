<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * @file Identity.php
 * @brief Immutable value object describing an authenticated principal.
 */

/**
 * @class Identity
 * @brief Carries the identifier and granted roles of a user or service.
 */
final class Identity
{
    /**
     * @var list<string> Normalized, de-duplicated role names.
     */
    public readonly array $roles;

    /**
     * @brief Identity constructor.
     *
     * @param int|string $id Primary key of the user (or service identifier).
     * @param array<string> $roles Granted roles.
     */
    public function __construct(
        public readonly int|string $id,
        array $roles = []
    ) {
        $normalized = [];
        foreach ($roles as $role) {
            $role = trim((string)$role);
            if ($role !== '') {
                $normalized[$role] = true;
            }
        }

        $this->roles = array_keys($normalized);
    }
}
