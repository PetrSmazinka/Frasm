<?php

declare(strict_types=1);

/**
 * @file queue.php
 * @brief Job queue console tool: worker and maintenance of failed jobs.
 *
 * Usage:
 *   php bin/queue.php work [--queue=default] [--once] [--sleep=3] [--max-jobs=0] [--max-time=3600]
 *   php bin/queue.php stats | failed | retry [id] | flush-failed
 *
 * Cron (every minute, drains the queue and exits):  php bin/queue.php work --once
 * Daemon (systemd, restarts after max-time):        php bin/queue.php work
 */

use Core\Config\Config;
use Core\Container\Container;
use Core\Queue\DatabaseQueue;
use Core\Queue\QueueInterface;
use Core\Queue\Worker;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$container = Container::getInstance();
$action = $argv[1] ?? 'help';

$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches)) {
        $options[$matches[1]] = $matches[2] ?? true;
    }
}

try {
    /** @var QueueInterface $queue */
    $queue = $container->get(QueueInterface::class);

    switch ($action) {
        case 'work':
            $queueName = (string)($options['queue'] ?? 'default');
            DatabaseQueue::assertQueueName($queueName);
            $defaults = (array)Config::get('queue.worker', []);

            $totals = $container->get(Worker::class)->run($queueName, [
                'sleep'           => (int)($options['sleep'] ?? $defaults['sleep'] ?? 3),
                'batch'           => (int)($options['batch'] ?? $defaults['batch'] ?? 10),
                'max_jobs'        => (int)($options['max-jobs'] ?? 0),
                'max_time'        => (int)($options['max-time'] ?? $defaults['max_time'] ?? 3600),
                'memory_mb'       => (int)($options['memory'] ?? $defaults['memory_mb'] ?? 128),
                'stop_when_empty' => isset($options['once']),
            ]);

            echo "[" . date('Y-m-d H:i:s') . "] Worker finished: {$totals['processed']} processed, {$totals['failed']} failed.\n";
            break;

        case 'stats':
            if (!$queue instanceof DatabaseQueue) {
                throw new RuntimeException('Statistics are available for the database queue only.');
            }
            $stats = $queue->stats();
            if ($stats === []) {
                echo "Queue is empty.\n";
            }
            foreach ($stats as $row) {
                echo str_pad($row['queue'], 20) . "pending: {$row['pending']}, running: {$row['reserved']}, failed: {$row['failed']}\n";
            }
            break;

        case 'failed':
            if (!$queue instanceof DatabaseQueue) {
                throw new RuntimeException('Failed jobs are available for the database queue only.');
            }
            foreach ($queue->failed() as $row) {
                echo "#{$row['id']} [{$row['queue']}] {$row['job_class']} attempts={$row['attempts']} failed_at={$row['failed_at']}\n    {$row['last_error']}\n";
            }
            break;

        case 'retry':
            if (!$queue instanceof DatabaseQueue) {
                throw new RuntimeException('Retry is available for the database queue only.');
            }
            $id = isset($argv[2]) && ctype_digit($argv[2]) ? (int)$argv[2] : null;
            echo "✔ Re-queued " . $queue->retryFailed($id) . " job(s).\n";
            break;

        case 'flush-failed':
            if (!$queue instanceof DatabaseQueue) {
                throw new RuntimeException('Flush is available for the database queue only.');
            }
            echo "✔ Deleted " . $queue->flushFailed() . " failed job(s).\n";
            break;

        default:
            echo "Frasm Queue Tool\n";
            echo "----------------\n";
            echo "Commands:\n";
            echo "  work [--queue=default] [--once] [--sleep=3] [--max-jobs=N] [--max-time=S]\n";
            echo "                 Process jobs; --once exits when the queue is empty (cron mode)\n";
            echo "  stats          Pending / running / failed jobs per queue\n";
            echo "  failed         List failed jobs with their last error\n";
            echo "  retry [id]     Re-queue one or all failed jobs\n";
            echo "  flush-failed   Delete failed jobs\n";
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error: " . $e->getMessage() . "\n");
    exit(1);
}
