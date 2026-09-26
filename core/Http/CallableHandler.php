<?php

declare(strict_types=1);

namespace Core\Http;

use Closure;

/**
 * @file CallableHandler.php
 * @brief Adapter turning a closure into a RequestHandlerInterface.
 */

/**
 * @class CallableHandler
 * @brief Wraps `fn(Request): Response` as the innermost handler of a middleware pipeline.
 */
final class CallableHandler implements RequestHandlerInterface
{
    /**
     * @brief CallableHandler constructor.
     *
     * @param Closure(Request): Response $callback Handler implementation.
     */
    public function __construct(private readonly Closure $callback)
    {
    }

    /**
     * @brief Invokes the wrapped closure.
     *
     * @param Request $request Incoming request.
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return ($this->callback)($request);
    }
}
