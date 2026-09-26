<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;

/**
 * @file MiddlewareInterface.php
 * @brief Contract of an HTTP middleware (PSR-15 style).
 */

/**
 * @interface MiddlewareInterface
 * @brief Participates in request processing: may short-circuit, delegate to $next, or decorate the response.
 */
interface MiddlewareInterface
{
    /**
     * @brief Processes the request.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler in the pipeline.
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $next): Response;
}
