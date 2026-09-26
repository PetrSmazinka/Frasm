<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * @file HttpException.php
 * @brief Generic exception carrying an explicit HTTP status code and response headers.
 */

/**
 * @class HttpException
 * @brief Raised to abort request processing with a specific HTTP status (e.g. 400, 404, 429, 503).
 *
 * The exception code always equals the HTTP status code. Additional headers (e.g. Retry-After)
 * are copied into the error response by Core\Error\ErrorHandler.
 */
class HttpException extends CoreException
{
    /**
     * @brief HttpException constructor.
     *
     * @param int $statusCode HTTP status code (400-599).
     * @param string $message Error description (shown to the client only in debug mode).
     * @param array<string, string> $headers Additional response headers.
     * @param Throwable|null $previous Previous throwable.
     * @param array<string, mixed> $context Diagnostic payload.
     */
    public function __construct(
        int $statusCode,
        string $message = '',
        protected array $headers = [],
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $statusCode, $previous, $context);
    }

    /**
     * @brief Returns the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    /**
     * @brief Returns additional response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
