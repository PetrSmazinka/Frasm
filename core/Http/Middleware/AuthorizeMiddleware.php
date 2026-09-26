<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Auth\Auth;
use Core\Config\Config;
use Core\Exceptions\AuthException;
use Core\Exceptions\CoreException;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Core\Routing\Route;

/**
 * @file AuthorizeMiddleware.php
 * @brief Enforces authentication and role requirements of a route.
 */

/**
 * @class AuthorizeMiddleware
 * @brief Requires an authenticated identity holding at least one of the required roles.
 *
 * Added automatically by the router for routes carrying #[Authorize]; can also be applied
 * explicitly as `auth` or `auth:role1,role2`. Explicit parameters take precedence over
 * the route's #[Authorize] roles. Unauthenticated browser GET requests are redirected to
 * `auth.login_path` when configured.
 */
class AuthorizeMiddleware implements ParameterizedMiddlewareInterface
{
    /**
     * @var list<string>|null Roles supplied via middleware parameters.
     */
    protected ?array $roles = null;

    /**
     * @brief Sets the required roles.
     *
     * @param list<string> $parameters Role names.
     * @return void
     */
    public function setParameters(array $parameters): void
    {
        $this->roles = array_values(array_filter($parameters, fn(string $role): bool => $role !== ''));
    }

    /**
     * @brief Verifies authentication and roles.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     * @throws CoreException If the Auth module is disabled.
     * @throws AuthException 401 when unauthenticated, 403 when roles are missing.
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $route = $request->getAttribute('route');
        $requiredRoles = $this->roles
            ?? ($route instanceof Route ? (array)$route->getMetadata('auth_roles', []) : []);

        if (!(bool)Config::get('auth.enabled', true)) {
            throw new CoreException(
                "Route '{$request->path()}' requires authorization, but the Auth module is disabled in config/auth.php.",
                500
            );
        }

        if (!Auth::check()) {
            $loginPath = Config::get('auth.login_path');
            if (is_string($loginPath) && $loginPath !== '' && $request->method() === 'GET' && !$request->expectsJson()) {
                return Response::redirect($loginPath);
            }

            throw new AuthException("User is unauthenticated.", 401);
        }

        if (!empty($requiredRoles) && !Auth::hasAnyRole($requiredRoles)) {
            throw new AuthException("User does not have required permissions.", 403);
        }

        return $next->handle($request);
    }
}
