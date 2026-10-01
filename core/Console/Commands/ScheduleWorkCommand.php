<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Scheduling\Scheduler;
use Core\Scheduling\SchedulerDaemon;

/**
 * @file ScheduleWorkCommand.php
 * @brief Runs the scheduler as a long-running process instead of a cron entry.
 */

/**
 * @class ScheduleWorkCommand
 * @brief Starts `schedule:run` every minute; meant for a supervisor (Docker service, systemd) with
 *        `scheduler.runner = daemon`, or for trying scheduled tasks during development.
 */
final class ScheduleWorkCommand extends Command
{
    /**
     * @brief ScheduleWorkCommand constructor.
     *
     * @param SchedulerDaemon $daemon Scheduler daemon.
     */
    public function __construct(private readonly SchedulerDaemon $daemon)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'schedule:work';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Run schedule:run every minute (daemon for Docker or systemd, instead of cron)';
    }

    /**
     * @brief Returns additional help.
     *
     * @return string
     */
    public function help(): string
    {
        return "Set scheduler.runner = 'daemon' so that schedule:cron does not install a cron entry as well.\n"
            . "Every run is a new process, so a deployment needs no restart of the daemon.\n"
            . "Docker: run it as its own service with `init: true`, so SIGTERM reaches PHP.";
    }

    /**
     * @brief Runs the daemon loop until it receives SIGTERM/SIGINT.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (!Scheduler::isEnabled()) {
            $output->warning('The scheduler is disabled (scheduler.enabled = false): runs will not execute any task.');
        } elseif (Scheduler::runner() === Scheduler::RUNNER_CRON) {
            $output->warning("scheduler.runner is 'cron': if its cron entry is installed, both start the runs"
                . ' (tasks never run twice, but use only one; see schedule:cron).');
        }

        $output->info('Scheduler daemon started, running schedule:run at the beginning of every minute');

        $runs = $this->daemon->run([PHP_BINARY, FRASM_ROOT_DIR . '/bin/frasm', 'schedule:run'], FRASM_ROOT_DIR);

        $output->success("Scheduler daemon stopped after {$runs} runs");
        return 0;
    }
}
