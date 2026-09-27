<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Scheduling\Scheduler;
use Core\Scheduling\TaskStore;

/**
 * @file ScheduleListCommand.php
 * @brief Lists scheduled tasks and their last runs.
 */
final class ScheduleListCommand extends Command
{
    /**
     * @brief ScheduleListCommand constructor.
     *
     * @param Scheduler $scheduler Scheduler.
     * @param TaskStore $store Task state.
     */
    public function __construct(private readonly Scheduler $scheduler, private readonly TaskStore $store)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'schedule:list';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'List #[Schedule] tasks with their last run';
    }

    /**
     * @brief Prints the task table.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $tasks = $this->scheduler->tasks();
        if ($tasks === []) {
            $output->success('No scheduled tasks (add #[Schedule] to a controller action or an app/Tasks class)');
            return 0;
        }

        $states = $this->store->all();
        $rows = [];
        foreach ($tasks as $task) {
            $state = $states[$task->key()] ?? [];
            $running = !empty($state['locked_until']) && strtotime((string)$state['locked_until']) > time();

            $rows[] = [
                $task->key(),
                $task->schedule->describe(),
                (string)($state['last_started_at'] ?? '-'),
                $running ? 'running' : (string)($state['last_status'] ?? '-'),
                (string)($state['last_trigger'] ?? '-'),
                isset($state['last_duration_ms']) ? $state['last_duration_ms'] . ' ms' : '-',
            ];
        }

        $output->table(['Task', 'Schedule', 'Last start', 'Status', 'Trigger', 'Duration'], $rows);
        foreach ($tasks as $task) {
            $error = $states[$task->key()]['last_error'] ?? null;
            if (($states[$task->key()]['last_status'] ?? null) === 'failed' && $error) {
                $output->comment("{$task->key()}: {$error}");
            }
        }

        return 0;
    }
}
