<?php

declare(strict_types=1);

namespace Core\Session;

use Core\Config\Config;
use Core\Container\Container;
use Core\Http\Request;

/**
 * @file Session.php
 * @brief Object-oriented wrapper, flash message store, and security manager for PHP sessions.
 */

/**
 * @class Session
 * @brief Static session facade with secure cookie defaults and lazy start.
 *
 * Read operations never start a new session when the client did not send a session cookie,
 * so stateless API/M2M requests do not create session files or receive Set-Cookie headers.
 * Write operations start the session on demand.
 */
class Session
{
    private const FLASH_KEY = '_flash';

    /**
     * @brief Ensures session is started with secure defaults.
     *
     * Enforces strict mode (rejects uninitialized session IDs), cookie-only transport,
     * HttpOnly, SameSite and the Secure flag on HTTPS. Cookie name and SameSite policy can be
     * configured via `session.name` and `session.same_site`.
     *
     * @return void
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        if (!headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');

            $name = (string)Config::get('session.name', '');
            if ($name !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $name)) {
                session_name($name);
            }

            session_set_cookie_params([
                'lifetime' => (int)Config::get('session.lifetime', 0),
                'path'     => '/',
                'secure'   => self::request()->isSecure(),
                'httponly' => true,
                'samesite' => (string)Config::get('session.same_site', 'Lax'),
            ]);
        }

        session_start();
    }

    /**
     * @brief Checks whether a session is active or can be resumed from the client's cookie.
     *
     * @return bool
     */
    public static function exists(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $name = (string)Config::get('session.name', '') ?: session_name();
        return self::request()->cookie($name) !== null;
    }

    /**
     * @brief Reads a value from session.
     *
     * @param string $key Session key.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::resume()) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    /**
     * @brief Stores a value into session.
     *
     * @param string $key Session key.
     * @param mixed $value Value to store.
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * @brief Checks if a key exists in session.
     *
     * @param string $key Session key.
     * @return bool
     */
    public static function has(string $key): bool
    {
        return self::resume() && isset($_SESSION[$key]);
    }

    /**
     * @brief Removes a key from session.
     *
     * @param string $key Session key.
     * @return void
     */
    public static function remove(string $key): void
    {
        if (self::resume()) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * @brief Stores a one-time flash message into session.
     *
     * @param string $key Flash identifier (e.g. 'success', 'error', 'info').
     * @param mixed $value Message content or payload.
     * @return void
     */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[self::FLASH_KEY][$key] = $value;
    }

    /**
     * @brief Retrieves and simultaneously deletes a flash message.
     *
     * @param string $key Flash identifier.
     * @param mixed $default Fallback value if flash key is not set.
     * @return mixed Stored value or default.
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        if (!self::resume() || !isset($_SESSION[self::FLASH_KEY][$key])) {
            return $default;
        }

        $value = $_SESSION[self::FLASH_KEY][$key];
        unset($_SESSION[self::FLASH_KEY][$key]);

        if (empty($_SESSION[self::FLASH_KEY])) {
            unset($_SESSION[self::FLASH_KEY]);
        }

        return $value;
    }

    /**
     * @brief Checks if a specific flash key exists without clearing it.
     *
     * @param string $key Flash identifier.
     * @return bool
     */
    public static function hasFlash(string $key): bool
    {
        return self::resume() && isset($_SESSION[self::FLASH_KEY][$key]);
    }

    /**
     * @brief Regenerates session ID to prevent Session Fixation attacks.
     *
     * @param bool $deleteOldSession Whether to delete the old session data file.
     * @return bool
     */
    public static function regenerate(bool $deleteOldSession = true): bool
    {
        self::start();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * @brief Completely destroys the current session and clears memory.
     *
     * @return void
     */
    public static function destroy(): void
    {
        if (!self::resume()) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    /**
     * @brief Starts the session only when the client already has one.
     *
     * @return bool True when a session is active after the call.
     */
    protected static function resume(): bool
    {
        if (!self::exists()) {
            return false;
        }

        self::start();
        return session_status() === PHP_SESSION_ACTIVE;
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
