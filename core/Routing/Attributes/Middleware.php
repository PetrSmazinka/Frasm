<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Middleware.php
 * @brief Attribute attaching middleware to a controller class or action.
 */

/**
 * @class Middleware
 * @brief Declares middleware specifications (group, alias with parameters, or class name).
 *
 * Example: #[Middleware('throttle:5,60')] or #[Middleware('api', App\Http\AuditMiddleware::class)].
 * Class-level middleware runs before method-level middleware.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @var list<string> Middleware specifications in execution order.
     */
    public array $middleware;

    /**
     * @brief Middleware attribute constructor.
     *
     * @param string ...$middleware Middleware specifications.
     */
    public function __construct(string ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}
