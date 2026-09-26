<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Container\Container;

/**
 * @file Queue.php
 * @brief Static facade over the container-bound QueueInterface.
 */

/**
 * @class Queue
 * @brief Shortcut for enqueuing jobs: `Queue::push(new SendReportJob($id))`.
 */
final class Queue
{
    /**
     * @brief Prevents instantiation of the facade.
     */
    private function __construct()
    {
    }

    /**
     * @brief Enqueues a job on the configured queue backend.
     *
     * @param JobInterface $job Job to run.
     * @param int $delay Seconds before the job becomes available.
     * @param string $queue Queue name.
     * @return int Job id.
     */
    public static function push(JobInterface $job, int $delay = 0, string $queue = 'default'): int
    {
        return Container::getInstance()->get(QueueInterface::class)->push($job, $delay, $queue);
    }
}
