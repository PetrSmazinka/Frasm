<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Exception;
use Throwable;

/**
 * @file FrasmException.php
 * @brief Root exception for the entire Frasm framework.
 */

/**
 * @class FrasmException
 * @brief Base framework exception providing structured context tracking.
 */
class FrasmException extends Exception
{
    /**
     * @var array<string, mixed> Contextual metadata for diagnostics and logging.
     */
    protected array $context = [];

    /**
     * @param string $message Error message.
     * @param int $code Error status code.
     * @param Throwable|null $previous Previous throwable in chain.
     * @param array<string, mixed> $context Diagnostic payload.
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * @brief Returns diagnostic context payload.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @brief Fluent helper to attach debug data.
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function withContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;
        return $this;
    }
}