<?php

declare(strict_types=1);

namespace Core\Auth\Sso;

use Core\Auth\DatabaseUserProvider;
use Core\Auth\Identity;
use Core\Auth\UserProviderInterface;

/**
 * @file SsoUserProvider.php
 * @brief User provider of an SSO client: local accounts kept in sync with the SSO server.
 */

/**
 * @class SsoUserProvider
 * @brief Reads identities from `frasm_users` like DatabaseUserProvider, refreshing remote users from the server
 *        at most every sso.client.refresh seconds.
 *
 * Set 'auth.provider' => SsoUserProvider::class on the client. A user removed at the server, without a role of
 * the client any more or expired, is signed out on their next request after the refresh; a password change at the
 * server changes the stamp and ends the sessions here as well. When the server cannot be reached, the local copy
 * is used.
 */
class SsoUserProvider implements UserProviderInterface
{
    /**
     * @brief SsoUserProvider constructor.
     *
     * @param SsoClient $client SSO client.
     * @param DatabaseUserProvider $database Local accounts.
     */
    public function __construct(private readonly SsoClient $client, private readonly DatabaseUserProvider $database)
    {
    }

    /**
     * @brief Loads the identity of a user by local ID.
     *
     * @param int|string $id Local user ID.
     * @return Identity|null Null when the user does not exist, expired or has no role (lost access).
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function findIdentityById(int|string $id): ?Identity
    {
        if (!$this->client->refresh((int)$id)) {
            return null;
        }
        $identity = $this->database->findIdentityById($id);

        return $identity === null || $identity->roles === [] ? null : $identity;
    }
}
