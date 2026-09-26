<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Config\Config;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;

/**
 * @file CorsMiddleware.php
 * @brief Cross-Origin Resource Sharing support including preflight handling.
 */

/**
 * @class CorsMiddleware
 * @brief Adds CORS headers for allowed origins on configured paths and answers preflight requests.
 *
 * Configuration lives under `middleware.cors`. It must be registered globally, because preflight
 * OPTIONS requests do not match any route. With an empty `allowed_origins` list it is a no-op.
 */
class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @brief Handles preflight requests or decorates the response with CORS headers.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        /** @var array<string, mixed> $config */
        $config = (array)Config::get('middleware.cors', []);
        $allowedOrigins = array_map('strval', (array)($config['allowed_origins'] ?? []));

        if ($allowedOrigins === [] || !$this->pathAllowed($request->path(), (array)($config['paths'] ?? []))) {
            return $next->handle($request);
        }

        $origin = $request->header('Origin');
        $allowCredentials = (bool)($config['allow_credentials'] ?? false);
        $allowOrigin = $origin !== null ? $this->resolveAllowOrigin($origin, $allowedOrigins, $allowCredentials) : null;

        $isPreflight = $request->realMethod() === 'OPTIONS' && $request->header('Access-Control-Request-Method') !== null;

        if ($isPreflight) {
            $response = Response::noContent();
            if ($allowOrigin !== null) {
                $response->withHeaders([
                    'Access-Control-Allow-Methods' => implode(', ', (array)($config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])),
                    'Access-Control-Allow-Headers' => implode(', ', (array)($config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With'])),
                    'Access-Control-Max-Age'       => (string)(int)($config['max_age'] ?? 600),
                ]);
            }
        } else {
            $response = $next->handle($request);
            $exposed = (array)($config['exposed_headers'] ?? []);
            if ($allowOrigin !== null && $exposed !== []) {
                $response->header('Access-Control-Expose-Headers', implode(', ', $exposed));
            }
        }

        if ($allowOrigin !== null) {
            $response->header('Access-Control-Allow-Origin', $allowOrigin);
            if ($allowCredentials) {
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
        }

        if ($allowOrigin !== '*') {
            $response->header('Vary', 'Origin', false);
        }

        return $response;
    }

    /**
     * @brief Determines the Access-Control-Allow-Origin value for the request origin.
     *
     * @param string $origin Origin header value.
     * @param list<string> $allowedOrigins Configured origins ('*' allows any).
     * @param bool $allowCredentials Credentials mode (forbids the '*' wildcard response).
     * @return string|null Header value, or null when the origin is not allowed.
     */
    protected function resolveAllowOrigin(string $origin, array $allowedOrigins, bool $allowCredentials): ?string
    {
        if (in_array('*', $allowedOrigins, true)) {
            return $allowCredentials ? $origin : '*';
        }

        return in_array($origin, $allowedOrigins, true) ? $origin : null;
    }

    /**
     * @brief Checks whether CORS applies to the request path.
     *
     * @param string $path Normalized request path.
     * @param array<int, string> $patterns Path patterns (empty = all paths).
     * @return bool
     */
    protected function pathAllowed(string $path, array $patterns): bool
    {
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (CsrfMiddleware::pathMatches((string)$pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
