<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Auth\Auth;
use Core\Config\Config;
use Core\Exceptions\CoreException;
use Core\Http\Request;
use Core\Session\Session;

/**
 * @file Csrf.php
 * @brief Synchronizer-token CSRF protection bound to the user session.
 *
 * The token of a signed-in user is signed with the application key and bound to that user. Thanks to that a form
 * opened before the session expired is still accepted when the same request restores the sign-in from the
 * remember-me cookie (Auth::sessionRestored()); the token is then adopted by the new session. Without `app.key`
 * (or for a visitor) the token is plain random and only the session decides.
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
     * After Session::release() a missing token cannot be stored: an empty string is returned then
     * (a page rendered after the release, e.g. an error page, has no form to protect).
     *
     * @return string 64-character hexadecimal token, or '' (see above).
     */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        $user = self::user();

        // A signed-in user holds a token signed for them (a token from before the sign-in is replaced)
        $current = is_string($token) && $token !== '' && ($user === null || self::signedFor($token, $user) || !self::canSign());
        if (!$current) {
            if (Session::isReleased()) {
                return is_string($token) ? $token : '';
            }
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
        $user = self::user();
        $token = $user !== null && self::canSign() ? self::sign($user) : bin2hex(random_bytes(32));
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

        if (is_string($sessionToken) && $sessionToken !== '' && $submitted !== null && hash_equals($sessionToken, $submitted)) {
            return true;
        }

        // The session expired and this very request restored the sign-in from the remember-me cookie: the token of
        // the page that was open (signed for this user, not older than the cookie lives) is still good
        $user = self::user();
        if ($submitted === null || $user === null || !Auth::sessionRestored() || !self::signedFor($submitted, $user, true)) {
            return false;
        }
        if (!Session::isReleased()) {
            Session::set(self::SESSION_KEY, $submitted);
        }

        return true;
    }

    /**
     * @brief Returns the signed-in user (not a service of an API token) as a string.
     *
     * @return string|null
     */
    private static function user(): ?string
    {
        return Auth::check() && !Auth::isStateless() && Auth::id() !== null ? (string)Auth::id() : null;
    }

    /**
     * @brief Tells whether the token can be signed (the application key is set).
     *
     * @return bool
     */
    private static function canSign(): bool
    {
        try {
            Signer::sign('csrf-user', 'probe');

            return true;
        } catch (CoreException) {
            return false;
        }
    }

    /**
     * @brief Creates a token signed for a user: time (8 hex), nonce (24 hex), signature (32 hex).
     *
     * @param string $user User ID.
     * @return string 64-character hexadecimal token.
     */
    private static function sign(string $user): string
    {
        $body = sprintf('%08x', time()) . bin2hex(random_bytes(12));

        return $body . substr(Signer::sign('csrf-user', $body . '|' . $user), 0, 32);
    }

    /**
     * @brief Checks a token signed for a user.
     *
     * @param string $token Token.
     * @param string $user User ID.
     * @param bool $fresh Also require the token to be younger than the remember-me cookie lives.
     * @return bool
     */
    private static function signedFor(string $token, string $user, bool $fresh = false): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1 || !self::canSign()) {
            return false;
        }
        $body = substr($token, 0, 32);
        if (!hash_equals(substr(Signer::sign('csrf-user', $body . '|' . $user), 0, 32), substr($token, 32))) {
            return false;
        }

        return !$fresh || time() - (int)hexdec(substr($token, 0, 8)) <= max(1, (int)Config::get('auth.remember_lifetime_days', 30)) * 86400;
    }
}
