<?php

declare(strict_types=1);

namespace Core\Session;

/**
 * @file Session.php
 * @brief Object-oriented wrapper, flash message store, and security manager for PHP sessions.
 */
class Session
{
    private const FLASH_KEY = '_flash';

    /**
     * @brief Ensures session is started with secure defaults.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * @brief Reads a value from session.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * @brief Stores a value into session.
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * @brief Checks if a key exists in session.
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * @brief Removes a key from session.
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * @brief Stores a one-time flash message into session.
     *
     * @param string $key Flash identifier (e.g. 'success', 'error', 'info').
     * @param mixed $value Message content or payload.
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
        self::start();
        if (!isset($_SESSION[self::FLASH_KEY][$key])) {
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
     */
    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION[self::FLASH_KEY][$key]);
    }

    /**
     * @brief Regenerates session ID to prevent Session Fixation attacks.
     */
    public static function regenerate(bool $deleteOldSession = true): bool
    {
        self::start();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * @brief Completely destroys the current session and clears memory.
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}