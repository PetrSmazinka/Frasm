<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Core\Logger\Logger;
use Core\Logger\LoggerInterface;

/**
 * @file SchedulerDaemon.php
 * @brief Long-running replacement of the scheduler cron entry (containers, systemd).
 */

/**
 * @class SchedulerDaemon
 * @brief Starts a command (`php bin/frasm schedule:run`) at the beginning of every minute, exactly like cron would.
 *
 * Every run is a separate process, so it loads fresh code after a deployment, a slow task does not delay the
 * others and a fatal error does not stop the daemon. Overlapping runs are safe: tasks are claimed atomically in
 * TaskStore. On SIGTERM/SIGINT (with ext-pcntl) the daemon starts nothing more and waits for running processes;
 * without ext-pcntl the signal terminates it right away (in Docker use `init: true` to deliver it).
 */
final class SchedulerDaemon
{
    /**
     * @var int Longest sleep in seconds between checks for a stop request and for finished runs.
     */
    private const TICK = 5;

    /**
     * @var bool Set by signal handlers to stop before the next run.
     */
    private bool $shouldStop = false;

    /**
     * @var list<resource> Processes started by run() that have not been reaped yet.
     */
    private array $processes = [];

    /**
     * @brief SchedulerDaemon constructor.
     *
     * @param LoggerInterface $logger Logger for processes that cannot be started.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @brief Starts the command at the beginning of every minute until stopped.
     *
     * The command's standard output is discarded (task results are in `schedule:list` and in the log),
     * its error output goes to the daemon's error output (e.g. `docker logs`).
     *
     * @param list<string> $command Command and its arguments, started without a shell.
     * @param string $directory Working directory of the command.
     * @return int Number of started runs.
     */
    public function run(array $command, string $directory): int
    {
        $this->installSignalHandlers();
        $runs = 0;

        while ($this->sleepUntil((intdiv(time(), 60) + 1) * 60)) {
            if ($this->start($command, $directory)) {
                $runs++;
            }
        }

        while ($this->processes !== []) {
            sleep(1);
            $this->reap();
        }

        return $runs;
    }

    /**
     * @brief Requests a stop; running processes are waited for.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * @brief Sleeps until the given time while reaping finished processes.
     *
     * @param int $timestamp Unix time to wake up at.
     * @return bool False when a stop was requested meanwhile.
     */
    private function sleepUntil(int $timestamp): bool
    {
        while (!$this->shouldStop && ($left = $timestamp - time()) > 0) {
            // A signal interrupts sleep(), so a stop request is noticed at once
            sleep(min($left, self::TICK));
            $this->reap();
        }

        return !$this->shouldStop;
    }

    /**
     * @brief Starts one run in the background.
     *
     * @param list<string> $command Command and its arguments.
     * @param string $directory Working directory.
     * @return bool False when the process could not be started.
     */
    private function start(array $command, string $directory): bool
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR],
            $pipes,
            $directory
        );

        if (!is_resource($process)) {
            $this->logger->error('Scheduler daemon cannot start {command}', ['command' => implode(' ', $command)]);
            if ($this->logger instanceof Logger) {
                $this->logger->flush();
            }
            return false;
        }

        $this->processes[] = $process;
        return true;
    }

    /**
     * @brief Closes the processes that have finished (no zombies are left behind).
     *
     * @return void
     */
    private function reap(): void
    {
        foreach ($this->processes as $index => $process) {
            if (!proc_get_status($process)['running']) {
                proc_close($process);
                unset($this->processes[$index]);
            }
        }

        $this->processes = array_values($this->processes);
    }

    /**
     * @brief Registers SIGTERM/SIGINT handlers when ext-pcntl is available.
     *
     * @return void
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());
    }
}
