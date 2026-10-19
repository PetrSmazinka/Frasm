<?php

declare(strict_types=1);

namespace Core\Auth\Sso;

use Core\Auth\Avatars;
use Core\Auth\DatabaseUserProvider;
use Core\Auth\PasswordResets;
use Core\Config\Config;
use Core\DB\DB;

/**
 * @file SsoServer.php
 * @brief Identity provider side of single sign-on: applications on other domains sign users in with the
 *        accounts of this one.
 */

/**
 * @class SsoServer
 * @brief Issues one-time authorization codes, checks passwords for clients and describes users to them.
 *
 * A client is an API token (`php bin/frasm token:create <client> --roles=sso.client`) whose service name has
 * an entry in sso.server.clients with its redirect URIs and role prefix. A client only sees the roles with its
 * prefix, without the prefix ('manevr.finance' → 'finance'), and only users having at least one of them.
 *
 * Identity shape (sent to clients): {id: string, username, name, email, roles: list<string>, expires_at
 * (Y-m-d H:i:s|null), stamp (changes with the password)}.
 *
 * A client may create accounts (createUser(), e.g. time-limited guests) and manage only those it created (manage():
 * validity, new password); other accounts it can only give or take its roles.
 * A client with a `notify` URL (its POST /_frasm/sso/changed) is told at once when an account changes (notify(): the
 * changes through the client API do it here, the application calls it after its own changes – profile, photo,
 * administration); the message carries only the ID, the client then reads the identity with its own token, so a
 * forged message changes nothing. Without it clients see a change within sso.client.refresh seconds of a request of
 * that user.
 */
class SsoServer
{
    /**
     * @var string Hash compared when the user does not exist, so timing does not reveal accounts.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$FHyYIXEq/2bKZ90urZnu/OG5ETC/RdB7EvfR1qe3pSxHL1OaIGBS.';

    /**
     * @brief Checks whether this application acts as an SSO server.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Config::get('sso.server.enabled', false);
    }

    /**
     * @var \Closure(string, string): void|null Sender of the notifications (tests): (URL, JSON body).
     */
    public static ?\Closure $notifier = null;

    /**
     * @brief Tells the clients with a `notify` URL that an account changed (name, e-mail, phone, photo, roles,
     *        password, validity), so they refresh their copy at once.
     *
     * Each client gets a short POST {id} (2 s at most, all at once); an unreachable client is skipped – it refreshes
     * the copy later by itself.
     *
     * @param int $userId User ID.
     * @param string|null $except Client that made the change (it already has the new identity).
     * @return void
     */
    public static function notify(int $userId, ?string $except = null): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $body = json_encode(['id' => (string)$userId], JSON_THROW_ON_ERROR);
        $urls = [];
        foreach ((array)Config::get('sso.server.clients', []) as $name => $config) {
            $url = is_array($config) ? (string)($config['notify'] ?? '') : '';
            if ($name !== $except && preg_match('#^https://[^\s]+$#D', $url)) {
                $urls[] = $url;
            }
        }
        if ($urls === []) {
            return;
        }
        if (self::$notifier !== null) {
            foreach ($urls as $url) {
                (self::$notifier)($url, $body);
            }
            return;
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            curl_multi_add_handle($multi, $curl);
            $handles[] = $curl;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);
        foreach ($handles as $curl) {
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
    }

    /**
     * @brief Returns the configuration of a client.
     *
     * @param string $client Service name of its API token.
     * @return array{name: string, redirect_uris: list<string>, role_prefix: string}|null
     */
    public function client(string $client): ?array
    {
        $config = Config::get("sso.server.clients.{$client}");
        if (!is_array($config) || trim((string)($config['role_prefix'] ?? '')) === '') {
            return null;
        }

        return [
            'name'          => (string)($config['name'] ?? $client),
            'redirect_uris' => array_values(array_map('strval', (array)($config['redirect_uris'] ?? []))),
            'role_prefix'   => (string)$config['role_prefix'],
        ];
    }

    /**
     * @brief Returns the client of the current API token request ('service:<name>' identity).
     *
     * @param int|string|null $authId Auth::id() of the request.
     * @return string|null Client name, or null when the caller is not a configured client.
     */
    public function clientOf(int|string|null $authId): ?string
    {
        $authId = (string)$authId;
        if (!str_starts_with($authId, 'service:')) {
            return null;
        }
        $client = substr($authId, 8);

        return $this->client($client) !== null ? $client : null;
    }

    /**
     * @brief Creates a one-time authorization code for a signed-in user.
     *
     * @param string $client Client name.
     * @param int $userId User ID.
     * @param string $redirectUri Redirect URI the code is bound to.
     * @param string $challenge PKCE S256 challenge.
     * @return string Plain code (only its hash is stored).
     */
    public function issueCode(string $client, int $userId, string $redirectUri, string $challenge): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db = DB::getInstance();
        $db->query('DELETE FROM `frasm_sso_codes` WHERE `expires_at` <= NOW()');
        $db->query(
            'INSERT INTO `frasm_sso_codes` (`code_hash`, `client`, `user_id`, `redirect_uri`, `challenge`, `expires_at`)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)',
            [hash('sha256', $code), $client, $userId, $redirectUri, $challenge, max(10, (int)Config::get('sso.server.code_ttl', 60))]
        );

        return $code;
    }

    /**
     * @brief Redeems a code: valid once, for the same client and redirect URI, with the matching PKCE verifier.
     *
     * @param string $client Client name.
     * @param string $code Plain code.
     * @param string $redirectUri Redirect URI of the authorization request.
     * @param string $verifier PKCE code verifier.
     * @return array<string, mixed>|null Identity, or null when the code is invalid, used or expired.
     */
    public function redeemCode(string $client, string $code, string $redirectUri, string $verifier): ?array
    {
        $db = DB::getInstance();
        $userId = $db->transactional(function (DB $db) use ($code, $client, $redirectUri, $verifier): ?int {
            $row = $db->selectOne(
                'SELECT `client`, `user_id`, `redirect_uri`, `challenge` FROM `frasm_sso_codes`
                 WHERE `code_hash` = ? AND `expires_at` > NOW() FOR UPDATE',
                [hash('sha256', $code)]
            );
            // Single use whatever the outcome: a guessed or replayed code burns the real one
            $db->query('DELETE FROM `frasm_sso_codes` WHERE `code_hash` = ?', [hash('sha256', $code)]);
            if ($row === null || !hash_equals((string)$row['client'], $client) || !hash_equals((string)$row['redirect_uri'], $redirectUri)) {
                return null;
            }
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            return hash_equals((string)$row['challenge'], $expected) ? (int)$row['user_id'] : null;
        });

        return $userId === null ? null : $this->identity($client, $userId);
    }

    /**
     * @brief Checks a user name (or e-mail) and password for a client.
     *
     * @param string $client Client name.
     * @param string $identifier User name or e-mail.
     * @param string $password Password.
     * @return array<string, mixed>|null Identity, or null when the credentials are wrong or the account expired.
     */
    public function verifyPassword(string $client, string $identifier, string $password): ?array
    {
        $user = DB::getInstance()->selectOne(
            'SELECT `id`, `password_hash` FROM `frasm_users`
             WHERE (`username` = ? OR `email` = ?) AND (`expires_at` IS NULL OR `expires_at` > NOW()) LIMIT 1',
            [$identifier, $identifier]
        );
        $valid = password_verify($password, $user !== null ? (string)$user['password_hash'] : self::DUMMY_PASSWORD_HASH);

        return $user !== null && $valid ? $this->identity($client, (int)$user['id']) : null;
    }

    /**
     * @brief Issues a link to set a new password for a user of a client (the client sends it in its own e-mail).
     *
     * @param string $client Client name.
     * @param string $identifier User name or e-mail.
     * @return array{token: string, identity: array<string, mixed>}|null Null when no valid account of the client has it.
     */
    public function requestPasswordReset(string $client, string $identifier): ?array
    {
        $userId = DB::getInstance()->selectValue(
            'SELECT `id` FROM `frasm_users` WHERE (`username` = ? OR `email` = ?) AND (`expires_at` IS NULL OR `expires_at` > NOW()) LIMIT 1',
            [trim($identifier), trim($identifier)]
        );
        $identity = $userId === null ? null : $this->identity($client, (int)$userId);
        if ($identity === null) {
            return null;
        }

        return ['token' => (new PasswordResets())->issue((int)$userId, $client), 'identity' => $identity];
    }

    /**
     * @brief Sets a new password with a link issued for a client.
     *
     * @param string $client Client name.
     * @param string $token Token of the link.
     * @param string $password New password.
     * @return array<string, mixed>|null New identity, null when the link is invalid (or the user lost access).
     * @throws \InvalidArgumentException 'weak_password'.
     */
    public function completePasswordReset(string $client, string $token, string $password): ?array
    {
        $userId = (new PasswordResets())->complete($token, $password, $client);
        if ($userId !== null) {
            self::notify($userId, $client);
        }

        return $userId === null ? null : $this->identity($client, $userId);
    }

    /**
     * @brief Changes the profile of a user of a client (the user edits it in the client): name, e-mail, phone – each
     *        only when given.
     *
     * @param string $client Client name.
     * @param int $userId User ID.
     * @param array{name?: string, email?: string, phone?: string} $profile New values ('' phone = none).
     * @return array<string, mixed>|null New identity, or null when the user is not one of the client.
     * @throws \InvalidArgumentException 'invalid_name', 'invalid_email', 'email_taken' or 'invalid_phone'.
     */
    public function setProfile(string $client, int $userId, array $profile): ?array
    {
        if ($this->identity($client, $userId) === null) {
            return null;
        }
        $data = [];
        if (array_key_exists('name', $profile)) {
            $name = trim((string)preg_replace('/\s+/u', ' ', (string)$profile['name']));
            if ($name === '' || mb_strlen($name) > 255) {
                throw new \InvalidArgumentException('invalid_name');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('email', $profile)) {
            $email = trim((string)$profile['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
                throw new \InvalidArgumentException('invalid_email');
            }
            if (DB::getInstance()->selectValue('SELECT `id` FROM `frasm_users` WHERE (`email` = ? OR `username` = ?) AND `id` <> ?', [$email, $email, $userId]) !== null) {
                throw new \InvalidArgumentException('email_taken');
            }
            $data['email'] = $email;
        }
        if (array_key_exists('phone', $profile)) {
            $data['phone'] = self::phone((string)$profile['phone']);
        }
        if ($data !== []) {
            DB::getInstance()->update('frasm_users', $data, '`id` = ?', [$userId]);
            self::notify($userId, $client);
        }

        return $this->identity($client, $userId);
    }

    /**
     * @brief Returns the profile photo of a user of a client.
     *
     * @param string $client Client name.
     * @param int $userId User ID.
     * @return string|null File (JPEG), or null without a photo or when the user is not one of the client.
     */
    public function avatar(string $client, int $userId): ?string
    {
        return $this->identity($client, $userId) === null ? null : (Avatars::version($userId) === null ? null : Avatars::path($userId));
    }

    /**
     * @brief Checks and normalizes a phone number (digits, spaces, "+", "-", "(", ")"; spaces collapsed).
     *
     * @param string $phone Phone number.
     * @return string|null Null for an empty one.
     * @throws \InvalidArgumentException On an invalid one.
     */
    public static function phone(string $phone): ?string
    {
        $phone = trim((string)preg_replace('/\s+/u', ' ', $phone));
        if ($phone === '') {
            return null;
        }
        if (!preg_match('/^\+?[0-9 ()-]{3,30}$/D', $phone) || preg_match_all('/[0-9]/', $phone) < 3) {
            throw new \InvalidArgumentException('invalid_phone');
        }

        return $phone;
    }

    /**
     * @brief Describes a user to a client.
     *
     * @param string $client Client name.
     * @param int $userId User ID.
     * @return array<string, mixed>|null Identity, or null when the user does not exist, expired or has no role
     *                                   of the client (an identity with empty roles is never returned).
     */
    public function identity(string $client, int $userId): ?array
    {
        $row = DB::getInstance()->selectOne(
            'SELECT `id`, `username`, `name`, `email`, `phone`, `avatar`, `password_hash`, `permissions`, `expires_at` FROM `frasm_users`
             WHERE `id` = ? AND (`expires_at` IS NULL OR `expires_at` > NOW()) LIMIT 1',
            [$userId]
        );

        return $row === null ? null : $this->describe($client, $row);
    }

    /**
     * @brief Finds an account by exact user name or e-mail, also one without access to the client (so the client can
     *        grant it access with setRoles()).
     *
     * @param string $client Client name.
     * @param string $identifier User name or e-mail (exact match).
     * @return array{id: string, username: string, name: string, email: string, has_access: bool}|null Null when no
     *         such active account exists.
     */
    public function lookup(string $client, string $identifier): ?array
    {
        $row = DB::getInstance()->selectOne(
            'SELECT `id`, `username`, `name`, `email`, `phone`, `avatar`, `password_hash`, `permissions`, `expires_at` FROM `frasm_users`
             WHERE (`username` = ? OR `email` = ?) AND (`expires_at` IS NULL OR `expires_at` > NOW()) LIMIT 1',
            [$identifier, $identifier]
        );
        if ($row === null) {
            return null;
        }

        return [
            'id'         => (string)$row['id'],
            'username'   => (string)$row['username'],
            'name'       => (string)$row['name'],
            'email'      => (string)$row['email'],
            'has_access' => $this->describe($client, $row) !== null,
        ];
    }

    /**
     * @brief Lists the users of a client (those having at least one of its roles).
     *
     * @param string $client Client name.
     * @return list<array<string, mixed>> Identities sorted by name.
     */
    public function users(string $client): array
    {
        $prefix = (string)$this->client($client)['role_prefix'];
        $rows = DB::getInstance()->select(
            "SELECT `id`, `username`, `name`, `email`, `phone`, `avatar`, `password_hash`, `permissions`, `expires_at` FROM `frasm_users`
             WHERE CONCAT(',', REPLACE(`permissions`, ' ', '')) LIKE ? AND (`expires_at` IS NULL OR `expires_at` > NOW())
             ORDER BY `name`",
            ['%,' . self::escapeLike($prefix) . '%']
        );

        return array_values(array_filter(array_map(fn(array $row): ?array => $this->describe($client, $row), $rows)));
    }

    /**
     * @brief Replaces the roles of a client a user has; roles of other clients and of this application stay.
     *
     * @param string $client Client name.
     * @param int $userId User ID.
     * @param list<string> $roles Client roles without the prefix (validated: letters, digits, '.', '_', '-').
     * @return array<string, mixed>|null Identity after the change (null when the user lost access or does not exist).
     * @throws \InvalidArgumentException On an invalid role name.
     */
    public function setRoles(string $client, int $userId, array $roles): ?array
    {
        $prefix = (string)$this->client($client)['role_prefix'];
        foreach ($roles as $role) {
            if (!is_string($role) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $role)) {
                throw new \InvalidArgumentException('Invalid role name.');
            }
        }

        $db = DB::getInstance();
        $done = $db->transactional(function (DB $db) use ($userId, $prefix, $roles): bool {
            $current = $db->selectValue('SELECT `permissions` FROM `frasm_users` WHERE `id` = ? FOR UPDATE', [$userId]);
            if ($current === null) {
                return false;
            }
            $kept = array_filter(DatabaseUserProvider::parseRoles((string)$current), fn(string $role): bool => !str_starts_with($role, $prefix));
            $new = array_values(array_unique([...$kept, ...array_map(fn(string $role): string => $prefix . $role, $roles)]));
            $db->update('frasm_users', ['permissions' => implode(',', $new)], '`id` = ?', [$userId]);

            return true;
        });

        if ($done) {
            self::notify($userId, $client);
        }

        return $done ? $this->identity($client, $userId) : null;
    }

    /**
     * @brief Creates an account for a client (e.g. a time-limited guest of a band) with a generated password.
     *
     * The user name is made from the e-mail; the account remembers the client, which may then change its validity and
     * issue a new password (manage()).
     *
     * @param string $client Client name.
     * @param string $name Name.
     * @param string $email E-mail.
     * @param string|null $expiresAt Validity (Y-m-d H:i:s), null = without limit.
     * @param list<string> $roles Client roles without the prefix (at least one).
     * @return array{identity: array<string, mixed>, password: string}
     * @throws \InvalidArgumentException On invalid data, roles, or an e-mail of an existing account ('exists').
     */
    public function createUser(string $client, string $name, string $email, ?string $expiresAt, array $roles): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new \InvalidArgumentException('Invalid name or e-mail.');
        }
        if ($roles === [] || !self::validDate($expiresAt)) {
            throw new \InvalidArgumentException('Invalid roles or validity.');
        }
        $db = DB::getInstance();
        if ($db->selectValue('SELECT `id` FROM `frasm_users` WHERE `email` = ? OR `username` = ?', [$email, $email]) !== null) {
            throw new \InvalidArgumentException('exists');
        }
        $base = substr((string)preg_replace('/[^a-z0-9._-]+/', '', strtolower(strstr($email, '@', true) ?: 'host')), 0, 40) ?: 'host';
        $username = $base;
        for ($i = 2; $db->selectValue('SELECT `id` FROM `frasm_users` WHERE `username` = ?', [$username]) !== null; $i++) {
            $username = $base . $i;
        }
        $password = self::password();
        $id = (int)$db->insert('frasm_users', [
            'name' => $name, 'email' => $email, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'permissions' => '', 'expires_at' => $expiresAt, 'created_by_client' => $client,
        ]);
        $identity = $this->setRoles($client, $id, $roles) ?? throw new \InvalidArgumentException('Invalid roles or validity.');

        return ['identity' => $identity, 'password' => $password];
    }

    /**
     * @brief Changes an account the client created: its validity and/or a new password.
     *
     * @param string $client Client name.
     * @param int $userId User ID.
     * @param string|null|false $expiresAt New validity (Y-m-d H:i:s), null = without limit, false = keep.
     * @param bool $newPassword Generate a new password (the user is then signed out where the stamp is checked).
     * @return array{identity: array<string, mixed>|null, password: string|null}|null Null when the account does not
     *         exist or the client did not create it.
     * @throws \InvalidArgumentException On an invalid validity.
     */
    public function manage(string $client, int $userId, string|null|false $expiresAt, bool $newPassword): ?array
    {
        if ($expiresAt !== false && !self::validDate($expiresAt)) {
            throw new \InvalidArgumentException('Invalid validity.');
        }
        $db = DB::getInstance();
        if ($db->selectValue('SELECT `id` FROM `frasm_users` WHERE `id` = ? AND `created_by_client` = ?', [$userId, $client]) === null) {
            return null;
        }
        $row = [];
        if ($expiresAt !== false) {
            $row['expires_at'] = $expiresAt;
        }
        $password = $newPassword ? self::password() : null;
        if ($password !== null) {
            $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($row !== []) {
            $db->update('frasm_users', $row, '`id` = ?', [$userId]);
        }

        return ['identity' => $this->identity($client, $userId), 'password' => $password];
    }

    /**
     * @brief Checks a validity date (Y-m-d H:i:s or null).
     *
     * @param string|null $value Value.
     * @return bool
     */
    private static function validDate(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    /**
     * @brief Generates a readable password (no confusable characters).
     *
     * @return string
     */
    private static function password(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 14; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * @brief Builds the identity of a user row for a client.
     *
     * @param string $client Client name.
     * @param array<string, mixed> $row User row.
     * @return array<string, mixed>|null Null when the user has no role of the client.
     */
    private function describe(string $client, array $row): ?array
    {
        $prefix = (string)($this->client($client)['role_prefix'] ?? '');
        $roles = [];
        foreach (DatabaseUserProvider::parseRoles((string)$row['permissions']) as $role) {
            if ($prefix !== '' && str_starts_with($role, $prefix) && strlen($role) > strlen($prefix)) {
                $roles[] = substr($role, strlen($prefix));
            }
        }
        if ($roles === []) {
            return null;
        }

        return [
            'id'         => (string)$row['id'],
            'username'   => (string)$row['username'],
            'name'       => (string)$row['name'],
            'email'      => (string)$row['email'],
            'phone'      => (string)($row['phone'] ?? ''),
            // Version of the profile photo (the photo itself: GET users/{id}/avatar), null = none
            'avatar'     => isset($row['avatar']) && $row['avatar'] !== '' ? (string)$row['avatar'] : null,
            'roles'      => $roles,
            'expires_at' => $row['expires_at'] === null ? null : (string)$row['expires_at'],
            // Same digest as DatabaseUserProvider: changes with the password, reveals nothing about it
            'stamp'      => substr(hash('sha256', (string)$row['password_hash']), 0, 32),
        ];
    }

    /**
     * @brief Escapes LIKE wildcards.
     *
     * @param string $value Value.
     * @return string
     */
    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
