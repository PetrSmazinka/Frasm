<?php

declare(strict_types=1);

namespace Core\Auth;

use App\Models\User;
use Core\Config\Config;
use Core\DB\DB;
use Core\Session\Session;

/**
 * @file Auth.php
 * @brief High-level authentication, role, and persistent remember-me token manager.
 */
class Auth
{
    private const SESSION_USER_ID = '_auth_user_id';
    private const SESSION_ROLES = '_auth_user_roles';

    /**
     * @brief Logs user in, assigns roles, and regenerates session ID.
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
     */
    public static function logout(): void
    {
        self::clearRememberToken();

        Session::remove(self::SESSION_USER_ID);
        Session::remove(self::SESSION_ROLES);
        Session::regenerate(true);
    }

    public static function check(): bool
    {
        return Session::has(self::SESSION_USER_ID) && Session::get(self::SESSION_USER_ID) !== null;
    }

    public static function id(): int|string|null
    {
        return Session::get(self::SESSION_USER_ID);
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return (array)Session::get(self::SESSION_ROLES, []);
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    public static function hasAnyRole(array|string $roles): bool
    {
        $requiredRoles = (array)$roles;
        if (empty($requiredRoles)) {
            return true;
        }

        return !empty(array_intersect(self::roles(), $requiredRoles));
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
            // Potential theft detected: clear all tokens for this user
            $db->delete('user_remember_tokens', 'user_id = ?', [$tokenRecord['user_id']]);
            self::clearRememberCookie();
            return false;
        }

        // Fetch corresponding user and restore session
        $user =$db->selectOne('SELECT id, permissions FROM `users` WHERE `id` = ? LIMIT 1', [$tokenRecord['user_id']]);
        if (!$user) {
            return false;
        }

        $roles = User::parsePermissions((string)$user['permissions']);
        Session::regenerate(true);
        Session::set(self::SESSION_USER_ID, (int)$user['id']);
        Session::set(self::SESSION_ROLES, $roles);

        // Rotate token for improved security
        $db->delete('user_remember_tokens', 'id = ?', [$tokenRecord['id']]);
        self::issueRememberToken((int)$user['id']);

        return true;
    }

    /**
     * @brief Generates, stores, and issues a selector:validator remember cookie.
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