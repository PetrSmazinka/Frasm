<?php

declare(strict_types=1);

namespace Core\Queue;

/**
 * @file Job.php
 * @brief Convenience base class for queue jobs.
 */

/**
 * @class Job
 * @brief Provides default retry policy: 3 attempts with exponential backoff (10 s, 20 s, 40 s, ...).
 */
abstract class Job implements JobInterface
{
    /**
     * @brief Maximum number of attempts.
     *
     * @return int
     */
    public function maxAttempts(): int
    {
        return 3;
    }

    /**
     * @brief Exponential backoff capped at one hour.
     *
     * @param int $attempt Number of the attempt that just failed (1-based).
     * @return int Seconds.
     */
    public function backoff(int $attempt): int
    {
        return min(3600, 10 * (2 ** max(0, $attempt - 1)));
    }
}
