<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Queue\DatabaseQueue;

/**
 * @file QueueFlushCommand.php
 * @brief Deletes permanently failed jobs.
 */
final class QueueFlushCommand extends Command
{
    /**
     * @brief QueueFlushCommand constructor.
     *
     * @param DatabaseQueue $queue Database queue.
     */
    public function __construct(private readonly DatabaseQueue $queue)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'queue:flush';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Delete all failed jobs';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return ['force' => 'Do not ask for confirmation'];
    }

    /**
     * @brief Deletes failed jobs after confirmation.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (!$this->confirmed($input, $output, 'Delete all failed jobs?')) {
            return 1;
        }

        $output->success('Deleted ' . $this->queue->flushFailed() . ' failed job(s)');
        return 0;
    }
}
