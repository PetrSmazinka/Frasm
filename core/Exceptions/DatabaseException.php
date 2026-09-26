<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Throwable;

/**
 * @file DatabaseException.php
 * @brief Database connectivity and query execution exception.
 */

/**
 * @class DatabaseException
 * @brief Raised on low-level database connection, syntax, or constraint failures.
 */
class DatabaseException extends CoreException
{
    /**
     * @param string $message Error message.
     * @param int $code MySQL error code.
     * @param Throwable|null $previous Original driver exception (e.g. mysqli_sql_exception).
     * @param string $sql Executed SQL string.
     * @param array<int|string, mixed> $params Bound SQL parameters.
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        protected string $sql = "",
        protected array $params = []
    ) {
        $context = [
            'sql'    => $sql,
            'params' => $params,
        ];

        parent::__construct($message, $code, $previous, $context);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}