<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * @file QueueInterface.php
 * @brief Contract of a job queue backend.
 */

/**
 * @interface QueueInterface
 * @brief Stores jobs and hands them out to workers with at-least-once delivery.
 */
interface QueueInterface
{
    /**
     * @brief Enqueues a job.
     *
     * @param JobInterface $job Job to run.
     * @param int $delay Seconds before the job becomes available.
     * @param string $queue Queue name.
     * @return int Job id.
     */
    public function push(JobInterface $job, int $delay = 0, string $queue = 'default'): int;

    /**
     * @brief Reserves available jobs for a worker.
     *
     * @param string $queue Queue name.
     * @param int $limit Maximum number of jobs.
     * @return list<ReservedJob>
     */
    public function reserve(string $queue = 'default', int $limit = 10): array;

    /**
     * @brief Removes a successfully processed job.
     *
     * @param ReservedJob $job Reserved job.
     * @return void
     */
    public function delete(ReservedJob $job): void;

    /**
     * @brief Returns a job to the queue for a later retry, or marks it failed when attempts are exhausted.
     *
     * @param ReservedJob $job Reserved job.
     * @param string $error Failure description.
     * @param int $delay Seconds before the retry.
     * @param bool $fail Mark the job as permanently failed.
     * @return void
     */
    public function release(ReservedJob $job, string $error, int $delay, bool $fail): void;
}
