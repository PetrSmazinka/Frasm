<?php

declare(strict_types=1);

namespace Core\Auth;

use App\Models\User;
use Core\Config\Config;
use Core\DB\DB;
use Core\Session\Session;

/**
 * @file Auth.php
 * @brief High-level authentication manager handling stateful sessions, remember-me tokens, and stateless API service authorization.
 */
class Auth
{
    private const SESSION_USER_ID = '_auth_user_id';
    private const SESSION_ROLES = '_auth_user_roles';

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
    public static function login(int|string $userId, array|string $roles = 'user', bool$remember = false): void
    {
        Session::regenerate(true);
        Session::set(self::SESSION_USER_ID, $userId);

        $normalizedRoles = is_array($roles) ? array_values($roles) : [$roles];
        Session::set(self::SESSION_ROLES, $normalizedRoles);

        if ($remember) {
            self::issueRememberToken((int)$userId);
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
        Session::regenerate(true);
        self::$statelessIdentity = null;
    }

    /**
     * @brief Registers an in-memory identity for the current request lifecycle without session persistence.
     *
     * @param int|string $id Service or external entity identifier.
     * @param array<string>|string $roles Assigned permissions or roles.
     * @return void
     */
    public static function setStatelessUser(int|string $id, array|string$roles): void
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
     * @brief Checks if an active session exists or an API service has been authenticated in runtime.
     *
     * @return bool True if authenticated, false otherwise.
     */
    public static function check(): bool
    {
        if (self::$statelessIdentity !== null) {
            return true;
        }

        return Session::has(self::SESSION_USER_ID) && Session::get(self::SESSION_USER_ID) !== null;
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

        return (array)Session::get(self::SESSION_ROLES, []);
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
     * @param string $plainToken Plain token string from the Authorization header.
     * @return bool True if the token is valid and not expired, false otherwise.
     */
    public static function attemptTokenLogin(string $plainToken): bool
    {
        $tokenHash = hash('sha256', $plainToken);$db = DB::getInstance();

        $token =$db->selectOne(
            'SELECT `id`, `service_name`, `roles`, `expires_at` 
             FROM `api_tokens` 
             WHERE `token_hash` = ? 
               AND (`expires_at` IS NULL OR `expires_at` > NOW()) 
             LIMIT 1',
            [$tokenHash]
        );

        if (!$token) {
            return false;
        }

        // Update last activity timestamp
        $db->update('api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], '`id` = ?', [$token['id']]);

        // Register stateless session for request duration
        self::setStatelessUser('service:' . (string)$token['service_name'], (string)$token['roles']);

        return true;
    }

    /**
     * @brief Attempts to restore user session via persistent remember-me cookie.
     *
     * @return bool True if authentication succeeded, false otherwise.
     */
    public static function attemptRememberLogin(): bool
    {
        $cookieName = (string)Config::get('auth.remember_cookie', 'frasm_remember');$rawCookie = $_COOKIE[$cookieName] ?? null;

        if (!$rawCookie || !str_contains($rawCookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':',$rawCookie, 2);

        $db = DB::getInstance();
        $tokenRecord =$db->selectOne(
            'SELECT `id`, `user_id`, `validator_hash`, `expires_at` 
             FROM `user_remember_tokens` 
             WHERE `selector` = ? AND `expires_at` > NOW() 
             LIMIT 1',
            [$selector]
        );

        if (!$tokenRecord) {
            self::clearRememberCookie();
            return false;
        }

        $calculatedHash = hash('sha256',$validator);
        if (!hash_equals((string)$tokenRecord['validator_hash'],$calculatedHash)) {
            // Potential token hijacking detected: clear all tokens for this user
            $db->delete('user_remember_tokens', '`user_id` = ?', [$tokenRecord['user_id']]);
            self::clearRememberCookie();
            return false;
        }

        // Fetch corresponding user and restore session
        $user =$db->selectOne('SELECT `id`, `permissions` FROM `users` WHERE `id` = ? LIMIT 1', [$tokenRecord['user_id']]);
        if (!$user) {
            return false;
        }

        $roles = User::parsePermissions((string)$user['permissions']);
        Session::regenerate(true);
        Session::set(self::SESSION_USER_ID, (int)$user['id']);
        Session::set(self::SESSION_ROLES, $roles);

        // Rotate token for improved security
        $db->delete('user_remember_tokens', '`id` = ?', [$tokenRecord['id']]);
        self::issueRememberToken((int)$user['id']);

        return true;
    }

    /**
     * @brief Generates, stores, and issues a selector:validator remember cookie.
     *
     * @param int $userId Target user identifier.
     * @return void
     */
    protected static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));$validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256',$validator);

        $lifetimeDays = (int)Config::get('auth.remember_lifetime_days', 30);
        $expiresTimestamp = time() + ($lifetimeDays * 86400);
        $expiresAt = date('Y-m-d H:i:s',$expiresTimestamp);

        $db = DB::getInstance();$db->insert('user_remember_tokens', [
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => $validatorHash,
            'expires_at'     => $expiresAt,
        ]);

        $cookieName = (string)Config::get('auth.remember_cookie', 'frasm_remember');$cookieValue = "{$selector}:{$validator}";

        setcookie($cookieName,$cookieValue, [
            'expires'  => $expiresTimestamp,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) &&$_SERVER['HTTPS'] !== 'off',
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
        $cookieName = (string)Config::get('auth.remember_cookie', 'frasm_remember');$rawCookie = $_COOKIE[$cookieName] ?? null;

        if ($rawCookie && str_contains($rawCookie, ':')) {
            [$selector] = explode(':',$rawCookie, 2);
            $db = DB::getInstance();$db->delete('user_remember_tokens', '`selector` = ?', [$selector]);
        }

        self::clearRememberCookie();
    }

    /**
     * @brief Clears and expires the persistent remember-me cookie from the client browser.
     *
     * Sets cookie expiration into the past to trigger client-side deletion
     * and unsets the corresponding key from the $_COOKIE superglobal array.
     *
     * @return void
     */
    protected static function clearRememberCookie(): void
    {
        $cookieName = (string)Config::get('auth.remember_cookie', 'frasm_remember');
        setcookie($cookieName, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
        ]);
        unset($_COOKIE[$cookieName]);
    }

    /**
     * @brief Deletes all expired persistent remember-me tokens from the database.
     *
     * @return int Number of purged token records.
     */
    public static function pruneExpiredTokens(): int
    {
        $db = DB::getInstance();
        return $db->delete('user_remember_tokens', '`expires_at` <= NOW()');
    }
}