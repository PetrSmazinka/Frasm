<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Container\Container;

/**
 * @file JobInterface.php
 * @brief Contract of a unit of work executed asynchronously by the queue worker.
 */

/**
 * @interface JobInterface
 * @brief Serializable background job.
 *
 * Jobs are stored as JSON (class name + toPayload()) instead of PHP serialize(), so a row in the
 * jobs table can never instantiate arbitrary objects (no object injection).
 */
interface JobInterface
{
    /**
     * @brief Executes the job.
     *
     * @param Container $container Service container for resolving dependencies.
     * @return void
     * @throws \Throwable Any failure; the job is retried until maxAttempts() is reached.
     */
    public function handle(Container $container): void;

    /**
     * @brief Returns JSON-serializable data needed to rebuild the job.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * @brief Rebuilds the job from its payload.
     *
     * @param array<string, mixed> $payload Data produced by toPayload().
     * @return static
     */
    public static function fromPayload(array $payload): static;

    /**
     * @brief Maximum number of attempts before the job is marked as failed.
     *
     * @return int
     */
    public function maxAttempts(): int;

    /**
     * @brief Delay before the next attempt.
     *
     * @param int $attempt Number of the attempt that just failed (1-based).
     * @return int Seconds.
     */
    public function backoff(int $attempt): int;
}
