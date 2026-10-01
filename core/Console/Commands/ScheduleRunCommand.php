<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Scheduling\Scheduler;

/**
 * @file ScheduleRunCommand.php
 * @brief Runs due scheduled tasks (called every minute by cron or by schedule:work).
 */
final class ScheduleRunCommand extends Command
{
    /**
     * @brief ScheduleRunCommand constructor.
     *
     * @param Scheduler $scheduler Scheduler.
     */
    public function __construct(private readonly Scheduler $scheduler)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'schedule:run';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Run the #[Schedule] tasks that are due (cron, every minute)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return ['task=' => 'Run this task now regardless of its schedule ("Class::method")'];
    }

    /**
     * @brief Returns additional help.
     *
     * @return string
     */
    public function help(): string
    {
        return "Called every minute by the cron entry that `php bin/frasm schedule:cron` manages,\n"
            . "or by `php bin/frasm schedule:work` (scheduler.runner = daemon).\n"
            . "--task runs one task immediately, even while the scheduler is disabled.";
    }

    /**
     * @brief Runs due tasks or one explicit task.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $key = $input->option('task');

        if ($key !== null) {
            $task = $this->scheduler->find($key);
            if ($task === null) {
                $output->error("No scheduled task '{$key}'. See: php bin/frasm schedule:list");
                return 1;
            }

            $result = $this->scheduler->runNow($task);
            if ($result === null) {
                $output->warning("Task '{$task->key()}' is already running.");
                return 1;
            }
            $results = [$result];
        } else {
            if (!Scheduler::isEnabled()) {
                $output->warning('The scheduler is disabled (scheduler.enabled = false); nothing was run.');
                return 0;
            }
            $results = $this->scheduler->runDue();
        }

        $failed = 0;
        foreach ($results as $result) {
            if ($result['success']) {
                $output->success("{$result['task']} ({$result['duration_ms']} ms)");
            } else {
                $failed++;
                $output->error("{$result['task']}: {$result['error']}");
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
