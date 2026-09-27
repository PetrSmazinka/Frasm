<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Queue\DatabaseQueue;

/**
 * @file QueueRetryCommand.php
 * @brief Moves failed jobs back to the queue.
 */
final class QueueRetryCommand extends Command
{
    /**
     * @brief QueueRetryCommand constructor.
     *
     * @param DatabaseQueue $queue Database queue.
     */
    public function __construct(private readonly DatabaseQueue $queue)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'queue:retry';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Re-queue one failed job, or all of them';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['id?' => 'Job id (omit to retry all failed jobs)'];
    }

    /**
     * @brief Re-queues the jobs.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $id = $input->argument('id');
        if ($id !== null && !ctype_digit($id)) {
            $output->error('The job id must be a number.');
            return 1;
        }

        $count = $this->queue->retryFailed($id === null ? null : (int)$id);
        $output->success("Re-queued {$count} job(s)");

        return 0;
    }
}
