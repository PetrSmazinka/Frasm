<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Queue\DatabaseQueue;

/**
 * @file QueueStatsCommand.php
 * @brief Prints queue statistics.
 */
final class QueueStatsCommand extends Command
{
    /**
     * @brief QueueStatsCommand constructor.
     *
     * @param DatabaseQueue $queue Database queue.
     */
    public function __construct(private readonly DatabaseQueue $queue)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'queue:stats';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Show pending, running and failed jobs per queue';
    }

    /**
     * @brief Prints the statistics.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $stats = $this->queue->stats();
        if ($stats === []) {
            $output->success('The queue is empty');
            return 0;
        }

        $output->table(['Queue', 'Pending', 'Running', 'Failed'], array_map(
            fn(array $row): array => [$row['queue'], (string)$row['pending'], (string)$row['reserved'], (string)$row['failed']],
            $stats
        ));

        return 0;
    }
}
