<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Auth\Auth;
use Core\Config\Config;
use Core\Exceptions\CsrfException;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Core\Push\PushAction;
use Core\Security\Csrf;

/**
 * @file CsrfMiddleware.php
 * @brief Validates the CSRF token of state-changing requests.
 */

/**
 * @class CsrfMiddleware
 * @brief Rejects unsafe (POST/PUT/PATCH/DELETE) requests without a valid session CSRF token.
 *
 * Skipped for safe methods, for requests authenticated statelessly with a Bearer token
 * (browsers cannot attach that header cross-site) and for paths listed in `middleware.csrf_except`
 * (a trailing `*` matches any suffix, e.g. '/api/*'). A POST carrying a valid signed push action
 * token (X-Frasm-Push-Action, bound to the request path) is accepted as well; the verified action
 * is exposed as request attribute 'push_action'. Must run after AuthenticateMiddleware.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * @brief Validates the token and delegates to the next handler.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     * @throws CsrfException When the token is missing or invalid.
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        if ($request->isMethodSafe() || Auth::isStateless() || $this->isExcepted($request->path())) {
            return $next->handle($request);
        }

        $pushAction = PushAction::fromRequest($request);
        if ($pushAction !== null) {
            $request->setAttribute('push_action', $pushAction);
            return $next->handle($request);
        }

        if (!Csrf::validate(Csrf::tokenFromRequest($request))) {
            throw new CsrfException("Missing, invalid or expired CSRF token.", 403);
        }

        return $next->handle($request);
    }

    /**
     * @brief Checks the path against the configured exception patterns.
     *
     * @param string $path Normalized request path.
     * @return bool
     */
    protected function isExcepted(string $path): bool
    {
        foreach ((array)Config::get('middleware.csrf_except', []) as $pattern) {
            if (self::pathMatches((string)$pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Matches a path against a pattern where '*' matches any sequence of characters.
     *
     * @param string $pattern Pattern such as '/api/*'.
     * @param string $path Normalized request path.
     * @return bool
     */
    public static function pathMatches(string $pattern, string $path): bool
    {
        $pattern = '/' . ltrim($pattern, '/');
        if ($pattern !== '/' && !str_ends_with($pattern, '*')) {
            $pattern = rtrim($pattern, '/');
        }

        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#D';
        return preg_match($regex, $path) === 1;
    }
}
