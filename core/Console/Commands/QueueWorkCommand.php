<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Queue\DatabaseQueue;
use Core\Queue\Worker;

/**
 * @file QueueWorkCommand.php
 * @brief Runs the queue worker.
 */
final class QueueWorkCommand extends Command
{
    /**
     * @brief QueueWorkCommand constructor.
     *
     * @param Worker $worker Queue worker.
     */
    public function __construct(private readonly Worker $worker)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'queue:work';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Process queued jobs (daemon, or --once for cron)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'queue='    => 'Queue name (default: default)',
            'once'      => 'Exit when the queue is empty (cron mode)',
            'sleep='    => 'Seconds to wait when the queue is empty (queue.worker.sleep)',
            'batch='    => 'Jobs reserved per query (queue.worker.batch)',
            'max-jobs=' => 'Stop after this many jobs (0 = unlimited)',
            'max-time=' => 'Stop after this many seconds (queue.worker.max_time)',
            'memory='   => 'Stop when memory usage exceeds this many MiB (queue.worker.memory_mb)',
        ];
    }

    /**
     * @brief Returns usage examples.
     *
     * @return string
     */
    public function help(): string
    {
        return "Examples:\n"
            . "  php bin/frasm queue:work --once --max-time=55   # cron, every minute\n"
            . "  php bin/frasm queue:work                        # systemd service (restarts after max-time)";
    }

    /**
     * @brief Runs the worker loop.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $queue = $input->option('queue', 'default');
        DatabaseQueue::assertQueueName($queue);
        $defaults = (array)Config::get('queue.worker', []);

        $totals = $this->worker->run($queue, [
            'sleep'           => $input->intOption('sleep', (int)($defaults['sleep'] ?? 3)),
            'batch'           => $input->intOption('batch', (int)($defaults['batch'] ?? 10)),
            'max_jobs'        => $input->intOption('max-jobs', 0),
            'max_time'        => $input->intOption('max-time', (int)($defaults['max_time'] ?? 3600)),
            'memory_mb'       => $input->intOption('memory', (int)($defaults['memory_mb'] ?? 128)),
            'stop_when_empty' => $input->flag('once'),
        ]);

        $output->success("Worker finished: {$totals['processed']} processed, {$totals['failed']} failed");
        return 0;
    }
}
