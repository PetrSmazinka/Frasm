<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Http\Request;
use Core\Security\Signer;

/**
 * @file PushAction.php
 * @brief Signed authorization of background POST requests triggered by notification action buttons.
 */

/**
 * @class PushAction
 * @brief Issues and verifies tokens binding an action name to one same-origin path.
 *
 * The service worker cannot obtain the session CSRF token, so it sends the token embedded in the
 * (end-to-end encrypted) push payload in the `X-Frasm-Push-Action` header. CsrfMiddleware accepts
 * such a request instead of a CSRF token; the controller reads the verified action from the request
 * attribute 'push_action' and still applies its normal authorization (session cookies are sent).
 */
final class PushAction
{
    /**
     * @var string Request header carrying the token.
     */
    public const HEADER = 'X-Frasm-Push-Action';

    /**
     * @var string Signer purpose.
     */
    private const PURPOSE = 'push-action';

    /**
     * @brief Prevents instantiation of the static helper.
     */
    private function __construct()
    {
    }

    /**
     * @brief Creates a token for a path and action.
     *
     * @param string $path Target path (e.g. '/api/garage/close').
     * @param string $action Action identifier.
     * @param int $ttl Validity in seconds.
     * @return string
     * @throws \Core\Exceptions\CoreException If app.key is missing.
     */
    public static function token(string $path, string $action, int $ttl): string
    {
        return Signer::token(self::PURPOSE, ['p' => self::normalizePath($path), 'a' => $action], $ttl);
    }

    /**
     * @brief Verifies the token of a POST request against its path.
     *
     * @param Request $request Incoming request.
     * @return string|null Verified action identifier, or null when absent/invalid.
     * @throws \Core\Exceptions\CoreException If app.key is missing.
     */
    public static function fromRequest(Request $request): ?string
    {
        $token = $request->header(self::HEADER);
        if ($token === null || $token === '' || $request->method() !== 'POST') {
            return null;
        }

        $payload = Signer::parseToken(self::PURPOSE, $token);
        if ($payload === null || ($payload['p'] ?? null) !== $request->path() || !is_string($payload['a'] ?? null)) {
            return null;
        }

        return $payload['a'];
    }

    /**
     * @brief Normalizes a path the same way Request::path() does (query string ignored).
     *
     * @param string $path Path.
     * @return string
     */
    private static function normalizePath(string $path): string
    {
        $path = (string)parse_url($path, PHP_URL_PATH);
        return '/' . trim(rawurldecode($path), '/');
    }
}
