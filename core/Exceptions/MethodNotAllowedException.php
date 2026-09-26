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
    /**
     * @brief MethodNotAllowedException constructor.
     *
     * @param string $message Error description.
     * @param int $code HTTP status code (default 405).
     * @param Throwable|null $previous Previous throwable.
     * @param list<string> $allowedMethods HTTP methods accepted by the matched path (emitted in the Allow header).
     */
    public function __construct(
        string $message = "Method not allowed.",
        int $code = 405,
        ?Throwable $previous = null,
        protected array $allowedMethods = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @brief Returns HTTP methods accepted by the matched path.
     *
     * @return list<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
