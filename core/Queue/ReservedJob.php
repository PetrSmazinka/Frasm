<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * @file ReservedJob.php
 * @brief A job row reserved by a worker.
 */

/**
 * @class ReservedJob
 * @brief Queue record (id, class, payload, attempt counter) handed to the worker.
 */
final class ReservedJob
{
    /**
     * @brief ReservedJob constructor.
     *
     * @param int $id Job id.
     * @param string $queue Queue name.
     * @param string $class Job class name.
     * @param array<string, mixed> $payload Job payload.
     * @param int $attempts Attempt number of this execution (1-based).
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $class,
        public readonly array $payload,
        public readonly int $attempts
    ) {
    }
}
