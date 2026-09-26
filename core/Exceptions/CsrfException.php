<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * @file CsrfException.php
 * @brief Exception thrown when CSRF token verification fails.
 */

/**
 * @class CsrfException
 * @brief Raised on missing or invalid Cross-Site Request Forgery tokens (HTTP 419 / 403).
 */
class CsrfException extends CoreException
{
    /**
     * @param string $message Error description.
     * @param int $code HTTP response code (default 403 Forbidden).
     * @param Throwable|null $previous Previous throwable.
     */
    public function __construct(
        string $message = "CSRF token validation failed.",
        int $code = 403,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}