<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * @file MethodNotAllowedException.php
 * @brief Exception for invalid HTTP method on an existing route (405).
 */

/**
 * @class MethodNotAllowedException
 * @brief Raised when a route path exists but the requested HTTP verb is disallowed.
 */
class MethodNotAllowedException extends CoreException
{
    public function __construct(string $message = "Method not allowed.", int $code = 405, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}