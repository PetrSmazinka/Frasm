<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config\Config;
use Core\Container\Container;
use Core\DB\DB;
use Core\Http\Request;
use Core\Logger\Log;
use Core\Session\Session;

/**
 * @file Auth.php
 * @brief High-level authentication manager handling stateful sessions, remember-me tokens, and stateless API service authorization.
 */

/**
 * @class Auth
 * @brief Static authentication facade.
 *
 * User data (roles of a user restored from a remember-me cookie) is obtained exclusively through
 * the container-bound UserProviderInterface, keeping the core independent of the App\ domain.
 */
class Auth
{
    private const SESSION_USER_ID = '_auth_user_id';
    private const SESSION_ROLES = '_auth_user_roles';
    private const SESSION_STAMP = '_auth_stamp';

    /**
     * @var int Minimum number of seconds between two `last_used_at` writes of the same API token.
     */
    private const TOKEN_TOUCH_INTERVAL = 60;

    /**
     * @var array{id: int|string, roles: list<string>}|null Ephemeral in-memory identity for stateless API/Service requests.
     */
    protected static ?array $statelessIdentity = null;

    /**
     * @brief Logs user in, assigns roles, and regenerates session ID.
     *
     * @param int|string $userId Primary key of the authenticated user.
     * @param array<string>|string $roles Role name or list of roles.
     * @param bool $remember Whether to issue a long-lived remember cookie.
     * @return void
     */
    public static function login(int|string $userId, array|string $roles = 'user', bool $remember = false): void
    {
        Session::regenerate(true);
        Session::set(self::SESSION_USER_ID, $userId);
        self::storeStamp($userId);

        $normalizedRoles = is_array($roles) ? array_values($roles) : [$roles];
        Session::set(self::SESSION_ROLES, $normalizedRoles);

        if ($remember) {
            self::issueRememberToken($userId);
        }
    }

    /**
     * @brief Logs out current user, terminates remember-me cookie and clears token in DB.
     *
     * @return void
     */
    public static function logout(): void
    {
        self::clearRememberToken();

        Session::remove(self::SESSION_USER_ID);
        Session::remove(self::SESSION_ROLES);
        Session::remove(self::SESSION_STAMP);
        Session::regenerate(true);
        self::$statelessIdentity = null;
    }

    /**
     * @brief Re-reads the signed-in user from the user provider (once per request, see AuthenticateMiddleware).
     *
     * Roles changed by an administrator apply immediately, a deleted user is signed out, and so are
     * all sessions of a user whose password changed elsewhere (credential stamp). Disabled with
     * auth.refresh_identity = false. When the provider fails (database down), the session is kept.
     *
     * @return void
     */
    public static function refreshIdentity(): void
    {
        if (self::$statelessIdentity !== null || !(bool)Config::get('auth.refresh_identity', true)) {
            return;
        }

        $id = Session::get(self::SESSION_USER_ID);
        if ($id === null) {
            return;
        }

        try {
            $identity = self::userProvider()->findIdentityById($id);
        } catch (\Throwable $e) {
            Log::warning('Cannot refresh the signed-in identity: ' . $e->getMessage());
            return;
        }

        if ($identity === null) {
            self::logout();
            return;
        }

        if ($identity->stamp !== null) {
            $stored = Session::get(self::SESSION_STAMP);
            if (!is_string($stored)) {
                // Sessions started before stamps were recorded at login
                Session::set(self::SESSION_STAMP, $identity->stamp);
            } elseif (!hash_equals($stored, $identity->stamp)) {
                self::logout();
                return;
            }
        }

        if ($identity->roles !== self::roles()) {
            Session::set(self::SESSION_ROLES, $identity->roles);
        }
    }

    /**
     * @brief Keeps the current session valid after the signed-in user changed their own password
     *        (other sessions of the user end on their next request).
     *
     * @return void
     */
    public static function acceptCredentialChange(): void
    {
        $id = Session::get(self::SESSION_USER_ID);
        if ($id === null) {
            return;
        }

        Session::regenerate(true);
        self::storeStamp($id);
    }

    /**
     * @brief Records the credential stamp of the user in the session (at login and after an own
     *        password change), so a later password change elsewhere ends this session.
     *
     * @param int|string $userId User ID.
     * @return void
     */
    protected static function storeStamp(int|string $userId): void
    {
        Session::remove(self::SESSION_STAMP);
        if (!(bool)Config::get('auth.refresh_identity', true)) {
            return;
        }

        try {
            $stamp = self::userProvider()->findIdentityById($userId)?->stamp;
        } catch (\Throwable $e) {
            Log::warning('Cannot read the credential stamp: ' . $e->getMessage());
            return;
        }
        if ($stamp !== null) {
            Session::set(self::SESSION_STAMP, $stamp);
        }
    }

    /**
     * @brief Registers an in-memory identity for the current request lifecycle without session persistence.
     *
     * @param int|string $id Service or external entity identifier.
     * @param array<string>|string $roles Assigned permissions or roles (comma-separated when string).
     * @return void
     */
    public static function setStatelessUser(int|string $id, array|string $roles): void
    {
        $normalizedRoles = is_array($roles)
            ? array_values($roles)
            : array_values(array_filter(array_map('trim', explode(',', $roles))));

        self::$statelessIdentity = [
            'id'    => $id,
            'roles' => $normalizedRoles,
        ];
    }

    /**
     * @brief Checks whether the current identity was authenticated statelessly (API token).
     *
     * @return bool
     */
    public static function isStateless(): bool
    {
        return self::$statelessIdentity !== null;
    }

    /**
     * @brief Checks if an active session exists or an API service has been authenticated in runtime.
     *
     * @return bool True if authenticated, false otherwise.
     */
    public static function check(): bool
    {
        if (self::$statelessIdentity !== null) {
            return true;
        }

        return Session::get(self::SESSION_USER_ID) !== null;
    }

    /**
     * @brief Retrieves the current authenticated entity identifier (User ID or Service identifier).
     *
     * @return int|string|null
     */
    public static function id(): int|string|null
    {
        if (self::$statelessIdentity !== null) {
            return self::$statelessIdentity['id'];
        }

        return Session::get(self::SESSION_USER_ID);
    }

    /**
     * @brief Returns list of active roles for current session or API service.
     *
     * @return list<string>
     */
    public static function roles(): array
    {
        if (self::$statelessIdentity !== null) {
            return self::$statelessIdentity['roles'];
        }

        return array_values((array)Session::get(self::SESSION_ROLES, []));
    }

    /**
     * @brief Checks if current identity has the specific role assigned.
     *
     * @param string $role Role identifier.
     * @return bool
     */
    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    /**
     * @brief Checks if current identity possesses at least one of the specified roles.
     *
     * @param array<string>|string $roles Single role or array of roles.
     * @return bool
     */
    public static function hasAnyRole(array|string $roles): bool
    {
        $requiredRoles = (array)$roles;
        if (empty($requiredRoles)) {
            return true;
        }

        return !empty(array_intersect(self::roles(), $requiredRoles));
    }

    /**
     * @brief Authenticates an external request via Bearer API token against the api_tokens table.
     *
     * The `last_used_at` column is refreshed at most once per TOKEN_TOUCH_INTERVAL to avoid a
     * database write on every API call (SD-card wear on Raspberry Pi deployments).
     *
     * @param string $plainToken Plain token string from the Authorization header.
     * @return bool True if the token is valid and not expired, false otherwise.
     */
    public static function attemptTokenLogin(string $plainToken): bool
    {
        $tokenHash = hash('sha256', $plainToken);
        $db = DB::getInstance();

        $token = $db->selectOne(
            'SELECT `id`, `service_name`, `roles`
             FROM `frasm_api_tokens`
             WHERE `token_hash` = ?
               AND (`expires_at` IS NULL OR `expires_at` > NOW())
             LIMIT 1',
            [$tokenHash]
        );

        if (!$token) {
            return false;
        }

        $db->query(
            'UPDATE `frasm_api_tokens` SET `last_used_at` = NOW()
             WHERE `id` = ? AND (`last_used_at` IS NULL OR `last_used_at` < NOW() - INTERVAL ? SECOND)',
            [$token['id'], self::TOKEN_TOUCH_INTERVAL]
        );

        // Register stateless session for request duration
        self::setStatelessUser('service:' . (string)$token['service_name'], (string)$token['roles']);

        return true;
    }

    /**
     * @brief Attempts to restore user session via persistent remember-me cookie.
     *
     * Roles are loaded through the configured UserProviderInterface. The token is rotated on success;
     * a validator mismatch (possible cookie theft) revokes all remember tokens of the user.
     *
     * @param string|null $rawCookie Cookie value "selector:validator"; null reads it from the current request.
     * @return bool True if authentication succeeded, false otherwise.
     */
    public static function attemptRememberLogin(?string $rawCookie = null): bool
    {
        $rawCookie ??= self::request()->cookie(self::rememberCookieName());

        if ($rawCookie === null || !str_contains($rawCookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $rawCookie, 2);

        $db = DB::getInstance();
        $tokenRecord = $db->selectOne(
            'SELECT `id`, `user_id`, `validator_hash`
             FROM `frasm_remember_tokens`
             WHERE `selector` = ? AND `expires_at` > NOW()
             LIMIT 1',
            [$selector]
        );

        if (!$tokenRecord) {
            self::clearRememberCookie();
            return false;
        }

        $calculatedHash = hash('sha256', $validator);
        if (!hash_equals((string)$tokenRecord['validator_hash'], $calculatedHash)) {
            // Potential token hijacking detected: clear all tokens for this user
            $db->delete('frasm_remember_tokens', '`user_id` = ?', [$tokenRecord['user_id']]);
            self::clearRememberCookie();
            Log::warning('Remember-me validator mismatch, all remember tokens of user {user_id} revoked.', [
                'user_id' => $tokenRecord['user_id'],
                'ip'      => self::request()->ip(),
            ]);
            return false;
        }

        // Token is single-use: rotate regardless of the outcome below
        $db->delete('frasm_remember_tokens', '`id` = ?', [$tokenRecord['id']]);

        $identity = self::userProvider()->findIdentityById($tokenRecord['user_id']);
        if ($identity === null) {
            self::clearRememberCookie();
            return false;
        }

        Session::regenerate(true);
        Session::set(self::SESSION_USER_ID, $identity->id);
        Session::set(self::SESSION_ROLES, $identity->roles);

        self::issueRememberToken($identity->id);

        return true;
    }

    /**
     * @brief Returns the configured remember-me cookie name.
     *
     * @return string
     */
    public static function rememberCookieName(): string
    {
        return (string)Config::get('auth.remember_cookie', 'frasm_remember');
    }

    /**
     * @brief Deletes all expired persistent remember-me tokens from the database.
     *
     * @return int Number of purged token records.
     */
    public static function pruneExpiredTokens(): int
    {
        $db = DB::getInstance();
        return $db->delete('frasm_remember_tokens', '`expires_at` <= NOW()');
    }

    /**
     * @brief Generates, stores, and issues a selector:validator remember cookie.
     *
     * The expiry is computed by the database (NOW() + INTERVAL) so it is compared against the same clock.
     *
     * @param int|string $userId Target user identifier.
     * @return void
     */
    protected static function issueRememberToken(int|string $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);

        $lifetimeDays = max(1, (int)Config::get('auth.remember_lifetime_days', 30));

        DB::getInstance()->query(
            'INSERT INTO `frasm_remember_tokens` (`user_id`, `selector`, `validator_hash`, `expires_at`)
             VALUES (?, ?, ?, NOW() + INTERVAL ? DAY)',
            [$userId, $selector, $validatorHash, $lifetimeDays]
        );

        setcookie(self::rememberCookieName(), "{$selector}:{$validator}", [
            'expires'  => time() + ($lifetimeDays * 86400),
            'path'     => '/',
            'secure'   => self::request()->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @brief Invalidates database remember tokens and deletes client cookie.
     *
     * @return void
     */
    protected static function clearRememberToken(): void
    {
        $rawCookie = self::request()->cookie(self::rememberCookieName());

        if ($rawCookie !== null && str_contains($rawCookie, ':')) {
            [$selector] = explode(':', $rawCookie, 2);
            DB::getInstance()->delete('frasm_remember_tokens', '`selector` = ?', [$selector]);
        }

        self::clearRememberCookie();
    }

    /**
     * @brief Clears and expires the persistent remember-me cookie from the client browser.
     *
     * @return void
     */
    protected static function clearRememberCookie(): void
    {
        setcookie(self::rememberCookieName(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::request()->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @brief Resolves the configured user provider from the container.
     *
     * @return UserProviderInterface
     * @throws \Core\Exceptions\ContainerException If no provider is bound.
     */
    protected static function userProvider(): UserProviderInterface
    {
        return Container::getInstance()->get(UserProviderInterface::class);
    }

    /**
     * @brief Returns the current request (registered by the front controller) or one built from globals.
     *
     * @return Request
     */
    protected static function request(): Request
    {
        $container = Container::getInstance();
        return $container->bound(Request::class) ? $container->get(Request::class) : Request::fromGlobals();
    }
}
