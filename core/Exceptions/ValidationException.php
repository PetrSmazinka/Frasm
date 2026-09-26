<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * @file ValidationException.php
 * @brief Exception raised when input data fails validation rules.
 */

/**
 * @class ValidationException
 * @brief Carries per-field validation error messages (HTTP 422).
 *
 * JSON clients receive the errors as a 422 payload; browser form submissions are redirected
 * back with the errors and old input flashed into the session (see Core\Error\ErrorHandler).
 */
class ValidationException extends CoreException
{
    /**
     * @brief ValidationException constructor.
     *
     * @param array<string, list<string>> $errors Error messages keyed by field name.
     * @param string $message Summary message.
     * @param Throwable|null $previous Previous throwable.
     */
    public function __construct(
        protected array $errors,
        string $message = 'The given data was invalid.',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 422, $previous);
    }

    /**
     * @brief Returns all validation errors keyed by field name.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
