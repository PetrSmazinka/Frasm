<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * @file RouteNotFoundException.php
 * @brief Exception for unresolved HTTP route paths (404).
 */

/**
 * @class RouteNotFoundException
 * @brief Raised when an incoming HTTP request path does not match any registered route.
 */
class RouteNotFoundException extends CoreException
{
    public function __construct(string $message = "Route not found.", int $code = 404, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}