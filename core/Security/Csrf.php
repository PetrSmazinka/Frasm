<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Http\Request;
use Core\Session\Session;

/**
 * @file Csrf.php
 * @brief Synchronizer-token CSRF protection bound to the user session.
 */

/**
 * @class Csrf
 * @brief Generates, exposes and validates the per-session CSRF token.
 */
final class Csrf
{
    /**
     * @var string Session key holding the token.
     */
    public const SESSION_KEY = '_frasm_csrf_token';

    /**
     * @var string Form field name carrying the token.
     */
    public const FIELD_NAME = '_csrf_token';

    /**
     * @var string HTTP header carrying the token for AJAX/fetch requests.
     */
    public const HEADER_NAME = 'X-CSRF-TOKEN';

    /**
     * @brief Prevents instantiation of the static helper.
     */
    private function __construct()
    {
    }

    /**
     * @brief Returns the current token, generating it (and starting the session) when absent.
     *
     * @return string 64-character hexadecimal token.
     */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = self::regenerate();
        }

        return $token;
    }

    /**
     * @brief Replaces the token with a fresh random value.
     *
     * @return string Newly generated token.
     */
    public static function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * @brief Returns a hidden form input containing the token.
     *
     * @return string HTML markup.
     */
    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . $token . '">';
    }

    /**
     * @brief Extracts the submitted token from the form body (or JSON body) or the X-CSRF-TOKEN header.
     *
     * @param Request $request Incoming request.
     * @return string|null
     */
    public static function tokenFromRequest(Request $request): ?string
    {
        $submitted = $request->post(self::FIELD_NAME);
        if (!is_string($submitted) || $submitted === '') {
            $submitted = $request->isJson() ? $request->input(self::FIELD_NAME) : null;
        }
        if (!is_string($submitted) || $submitted === '') {
            $submitted = $request->header(self::HEADER_NAME);
        }

        return is_string($submitted) && $submitted !== '' ? $submitted : null;
    }

    /**
     * @brief Compares a submitted token with the session token in constant time.
     *
     * @param string|null $submitted Token provided by the client.
     * @return bool True when both tokens exist and match.
     */
    public static function validate(?string $submitted): bool
    {
        $sessionToken = Session::get(self::SESSION_KEY);

        return is_string($sessionToken)
            && $sessionToken !== ''
            && $submitted !== null
            && hash_equals($sessionToken, $submitted);
    }
}
