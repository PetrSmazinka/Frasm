<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Queue\DatabaseQueue;

/**
 * @file QueueFailedCommand.php
 * @brief Lists permanently failed jobs.
 */
final class QueueFailedCommand extends Command
{
    /**
     * @brief QueueFailedCommand constructor.
     *
     * @param DatabaseQueue $queue Database queue.
     */
    public function __construct(private readonly DatabaseQueue $queue)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'queue:failed';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'List failed jobs with their last error';
    }

    /**
     * @brief Prints the failed jobs.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $failed = $this->queue->failed();
        if ($failed === []) {
            $output->success('No failed jobs');
            return 0;
        }

        foreach ($failed as $job) {
            $output->line("#{$job['id']}  {$job['job_class']}  [{$job['queue']}]  attempts: {$job['attempts']}  failed: {$job['failed_at']}");
            $output->comment('    ' . (string)$job['last_error']);
        }

        return 0;
    }
}
