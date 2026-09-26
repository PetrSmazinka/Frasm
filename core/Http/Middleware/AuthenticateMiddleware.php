<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Auth\Auth;
use Core\Config\Config;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Core\Logger\Log;

/**
 * @file AuthenticateMiddleware.php
 * @brief Resolves the caller identity from a Bearer API token or a remember-me cookie.
 */

/**
 * @class AuthenticateMiddleware
 * @brief Establishes (but does not require) authentication for the current request.
 *
 * 1. `Authorization: Bearer <token>` → stateless service identity (api_tokens table).
 * 2. Otherwise, when no session identity exists, a remember-me cookie restores the session.
 * Invalid API tokens are logged as warnings; successful API calls are written to the audit log
 * when `auth.api_audit_log` is enabled. Enforcement is left to AuthorizeMiddleware.
 */
class AuthenticateMiddleware implements MiddlewareInterface
{
    /**
     * @brief Authenticates the request and delegates to the next handler.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        if (!(bool)Config::get('auth.enabled', true)) {
            return $next->handle($request);
        }

        $token = $request->bearerToken();

        if ($token !== null) {
            if (!Auth::attemptTokenLogin($token)) {
                Log::warning('Rejected invalid or expired API token.', [
                    'ip'     => $request->ip(),
                    'method' => $request->method(),
                    'path'   => $request->path(),
                ]);

                return $next->handle($request);
            }

            $response = $next->handle($request);

            if ((bool)Config::get('auth.api_audit_log', true)) {
                Log::info('API {method} {path} by {identity} -> {status}', [
                    'identity' => (string)Auth::id(),
                    'method'   => $request->method(),
                    'path'     => $request->path(),
                    'status'   => $response->getStatusCode(),
                    'ip'       => $request->ip(),
                ]);
            }

            return $response;
        }

        if (!Auth::check()) {
            Auth::attemptRememberLogin($request->cookie(Auth::rememberCookieName()));
        }

        return $next->handle($request);
    }
}
