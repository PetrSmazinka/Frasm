<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Auth\Auth;
use Core\Exceptions\CoreException;
use Core\Exceptions\HttpException;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Core\Logger\Log;
use Core\RateLimit\RateLimiter;
use Core\Routing\Route;

/**
 * @file RateLimitMiddleware.php
 * @brief Throttles requests per client and route (brute-force and flooding protection).
 */

/**
 * @class RateLimitMiddleware
 * @brief Applies a fixed-window limit; specification `throttle:<maxAttempts>,<decaySeconds>[,<bucket>]`.
 *
 * The client is identified by its authenticated id, falling back to the IP address. The bucket
 * defaults to the matched route (method + pattern), so each throttled endpoint has its own counter;
 * pass an explicit bucket name to share a counter between routes. Exceeding the limit yields
 * HTTP 429 with a Retry-After header; the first rejection within a window is logged.
 */
class RateLimitMiddleware implements ParameterizedMiddlewareInterface
{
    /**
     * @var int Allowed requests per window.
     */
    protected int $maxAttempts = 60;

    /**
     * @var int Window length in seconds.
     */
    protected int $decaySeconds = 60;

    /**
     * @var string|null Explicit bucket name shared across routes.
     */
    protected ?string $bucket = null;

    /**
     * @brief RateLimitMiddleware constructor.
     *
     * @param RateLimiter $limiter Counter storage.
     */
    public function __construct(protected RateLimiter $limiter)
    {
    }

    /**
     * @brief Applies `maxAttempts, decaySeconds, bucket` parameters.
     *
     * @param list<string> $parameters Specification parameters.
     * @return void
     * @throws CoreException On non-positive numeric parameters.
     */
    public function setParameters(array $parameters): void
    {
        $max = filter_var($parameters[0] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decay = filter_var($parameters[1] ?? '60', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($max === false || $decay === false) {
            throw new CoreException("Invalid throttle parameters '" . implode(',', $parameters) . "'; expected positive integers 'max,seconds'.");
        }

        $this->maxAttempts = $max;
        $this->decaySeconds = $decay;
        $this->bucket = isset($parameters[2]) && $parameters[2] !== '' ? $parameters[2] : null;
    }

    /**
     * @brief Counts the request and rejects it when the limit is exceeded.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     * @throws HttpException 429 when the limit is exceeded.
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $key = $this->resolveKey($request);
        $state = $this->limiter->hit($key, $this->decaySeconds);
        $remaining = max(0, $this->maxAttempts - $state['hits']);

        $headers = [
            'X-RateLimit-Limit'     => (string)$this->maxAttempts,
            'X-RateLimit-Remaining' => (string)$remaining,
        ];

        if ($state['hits'] > $this->maxAttempts) {
            if ($state['hits'] === $this->maxAttempts + 1) {
                Log::warning('Rate limit exceeded for {key}.', [
                    'key'    => $key,
                    'ip'     => $request->ip(),
                    'method' => $request->method(),
                    'path'   => $request->path(),
                ]);
            }

            throw new HttpException(429, 'Too many requests.', $headers + [
                'Retry-After' => (string)max(1, $state['retry_after']),
            ]);
        }

        return $next->handle($request)->withHeaders($headers);
    }

    /**
     * @brief Builds the limiter key from bucket and client identity.
     *
     * @param Request $request Incoming request.
     * @return string
     */
    protected function resolveKey(Request $request): string
    {
        $route = $request->getAttribute('route');
        $bucket = $this->bucket
            ?? ($route instanceof Route ? $route->getMethod() . ' ' . $route->getPath() : $request->method() . ' ' . $request->path());

        $identity = Auth::check() ? 'id:' . (string)Auth::id() : 'ip:' . $request->ip();

        return "throttle|{$bucket}|{$identity}";
    }
}
