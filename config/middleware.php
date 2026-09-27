<?php

declare(strict_types=1);

/**
 * HTTP middleware configuration
 *
 * Specification forms accepted everywhere (global, groups, paths, #[Middleware]):
 *  - group name ('api'), alias with optional parameters ('throttle:5,60'),
 *  - fully qualified class implementing Core\Http\Middleware\MiddlewareInterface.
 */
return [
    /*
     * Global middleware, executed for every request before routing (outermost first).
     * CorsMiddleware must stay global because preflight OPTIONS requests match no route.
     */
    'global' => [
        \Core\Http\Middleware\CorsMiddleware::class,
        \Core\Http\Middleware\AuthenticateMiddleware::class,
        \Core\Http\Middleware\CsrfMiddleware::class,
    ],

    /*
     * Short names usable in groups, paths and #[Middleware] attributes.
     */
    'aliases' => [
        'auth'     => \Core\Http\Middleware\AuthorizeMiddleware::class,
        'throttle' => \Core\Http\Middleware\RateLimitMiddleware::class,
        'csrf'     => \Core\Http\Middleware\CsrfMiddleware::class,
        'task'     => \Core\Http\Middleware\TaskLockMiddleware::class,
    ],

    /*
     * Named middleware groups.
     */
    'groups' => [
        'api' => ['throttle:120,60'],
    ],

    /*
     * Route middleware applied by path prefix to every matched route below the prefix.
     */
    'paths' => [
        '/api' => ['api'],
    ],

    /*
     * Paths exempt from CSRF validation ('*' matches any suffix). Requests authenticated with a
     * Bearer API token are always exempt.
     */
    'csrf_except' => [],

    /*
     * One-in-N chance that a rate limiter hit also purges expired counters (0 disables).
     */
    'rate_limit_prune_probability' => 100,

    /*
     * Cross-Origin Resource Sharing. Empty 'allowed_origins' disables CORS handling.
     */
    'cors' => [
        'paths'             => ['/api/*'],
        'allowed_origins'   => [],
        'allowed_methods'   => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'allowed_headers'   => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],
        'exposed_headers'   => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],
        'max_age'           => 600,
        'allow_credentials' => false,
    ],
];
