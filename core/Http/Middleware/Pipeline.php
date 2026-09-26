<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Closure;
use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Throwable;

/**
 * @file Pipeline.php
 * @brief Onion-style middleware dispatcher.
 */

/**
 * @class Pipeline
 * @brief Passes the request through a list of middleware and finally to the core handler.
 *
 * When an exception renderer is supplied, any Throwable raised by a stage is converted into a
 * Response at that stage, so outer middleware can still decorate error responses
 * (e.g. CORS headers on a 401).
 */
class Pipeline implements RequestHandlerInterface
{
    /**
     * @brief Pipeline constructor.
     *
     * @param list<MiddlewareInterface> $middleware Middleware in execution order (outermost first).
     * @param RequestHandlerInterface $core Innermost handler producing the response.
     * @param (Closure(Throwable, Request): Response)|null $exceptionRenderer Converts exceptions to responses; null lets them propagate.
     * @param int $index Position of the current stage (internal).
     */
    public function __construct(
        protected array $middleware,
        protected RequestHandlerInterface $core,
        protected ?Closure $exceptionRenderer = null,
        protected int $index = 0
    ) {
    }

    /**
     * @brief Runs the current stage and delegates to the following one.
     *
     * @param Request $request Incoming request.
     * @return Response
     * @throws Throwable When no exception renderer is configured.
     */
    public function handle(Request $request): Response
    {
        try {
            if (!isset($this->middleware[$this->index])) {
                return $this->core->handle($request);
            }

            $next = new self($this->middleware, $this->core, $this->exceptionRenderer, $this->index + 1);
            return $this->middleware[$this->index]->process($request, $next);
        } catch (Throwable $e) {
            if ($this->exceptionRenderer === null) {
                throw $e;
            }

            return ($this->exceptionRenderer)($e, $request);
        }
    }
}
