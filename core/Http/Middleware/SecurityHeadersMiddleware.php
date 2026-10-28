<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;

/**
 * @file SecurityHeadersMiddleware.php
 * @brief Security headers of every page: no framing by other sites, no sniffing of types, HSTS.
 */

/**
 * @class SecurityHeadersMiddleware
 * @brief Adds the headers no response should miss (register it globally, first – it then sees the headers set by
 *        the middleware after it and keeps them).
 *
 * - framing only by the site itself (CSP frame-ancestors 'self', X-Frame-Options SAMEORIGIN) – against clickjacking of
 *   actions; a response that set X-Frame-Options itself (a stricter policy of a module) is left as it is;
 * - X-Content-Type-Options: nosniff, Referrer-Policy: strict-origin-when-cross-origin (unless set);
 * - Strict-Transport-Security over HTTPS (half a year).
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @brief Decorates the response.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (!$response->hasHeader('X-Frame-Options')) {
            $policy = (string)($response->getHeader('Content-Security-Policy') ?? '');
            if (!str_contains($policy, 'frame-ancestors')) {
                $response->header('Content-Security-Policy', ($policy === '' ? '' : rtrim($policy, '; ') . '; ') . "frame-ancestors 'self'");
            }
            $response->header('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$response->hasHeader('X-Content-Type-Options')) {
            $response->header('X-Content-Type-Options', 'nosniff');
        }
        if (!$response->hasHeader('Referrer-Policy')) {
            $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if ($request->isSecure() && !$response->hasHeader('Strict-Transport-Security')) {
            $response->header('Strict-Transport-Security', 'max-age=15552000');
        }

        return $response;
    }
}
