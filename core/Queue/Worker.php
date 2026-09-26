<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Container\Container;
use Core\DB\DB;
use Core\Exceptions\DatabaseException;
use Core\Logger\Logger;
use Core\Logger\LoggerInterface;
use Throwable;

/**
 * @file Worker.php
 * @brief Processes queued jobs (one-shot for cron or as a long-running daemon).
 */

/**
 * @class Worker
 * @brief Reserves jobs in batches, executes them and applies the retry policy.
 *
 * Daemon mode stops gracefully on SIGTERM/SIGINT (when ext-pcntl is available) after the current
 * job, and after the configured maximum runtime, job count or memory usage, so a supervisor
 * (systemd, cron) can restart it with fresh code.
 */
class Worker
{
    /**
     * @var bool Set by signal handlers to finish the loop after the current job.
     */
    protected bool $shouldStop = false;

    /**
     * @brief Worker constructor.
     *
     * @param QueueInterface $queue Queue backend.
     * @param Container $container Container passed to jobs.
     * @param LoggerInterface $logger Logger for job failures.
     */
    public function __construct(
        protected QueueInterface $queue,
        protected Container $container,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @brief Runs the worker loop.
     *
     * @param string $queueName Queue to consume.
     * @param array{sleep?: int, batch?: int, max_jobs?: int, max_time?: int, memory_mb?: int, stop_when_empty?: bool} $options
     *        sleep: seconds to wait when the queue is empty; batch: jobs reserved at once; max_jobs/max_time: stop limits
     *        (0 = unlimited); memory_mb: stop when exceeded; stop_when_empty: exit once the queue is drained (cron mode).
     * @return array{processed: int, failed: int} Totals.
     */
    public function run(string $queueName = 'default', array $options = []): array
    {
        $sleep = max(1, (int)($options['sleep'] ?? 3));
        $batch = max(1, (int)($options['batch'] ?? 10));
        $maxJobs = max(0, (int)($options['max_jobs'] ?? 0));
        $maxTime = max(0, (int)($options['max_time'] ?? 0));
        $memoryLimit = max(16, (int)($options['memory_mb'] ?? 128)) * 1024 * 1024;
        $stopWhenEmpty = (bool)($options['stop_when_empty'] ?? false);

        $this->installSignalHandlers();
        $started = time();
        $totals = ['processed' => 0, 'failed' => 0];

        while (!$this->shouldStop) {
            try {
                $jobs = $this->queue->reserve($queueName, $batch);
            } catch (DatabaseException $e) {
                // Connection lost (e.g. wait_timeout): reconnect on the next iteration
                $this->logger->warning('Queue worker lost the database connection: {message}', ['message' => $e->getMessage()]);
                DB::disconnect();
                sleep($sleep);
                continue;
            }

            if ($jobs === []) {
                if ($stopWhenEmpty) {
                    break;
                }
                sleep($sleep);
            }

            foreach ($jobs as $job) {
                $this->process($job) ? $totals['processed']++ : $totals['failed']++;
            }

            if (($maxJobs > 0 && $totals['processed'] + $totals['failed'] >= $maxJobs)
                || ($maxTime > 0 && time() - $started >= $maxTime)
                || memory_get_usage(true) >= $memoryLimit) {
                break;
            }
        }

        return $totals;
    }

    /**
     * @brief Executes one reserved job and deletes, retries or fails it.
     *
     * @param ReservedJob $reserved Reserved job.
     * @return bool True on success.
     */
    public function process(ReservedJob $reserved): bool
    {
        $job = null;

        try {
            if (!class_exists($reserved->class) || !is_subclass_of($reserved->class, JobInterface::class)) {
                throw new \RuntimeException("Job class '{$reserved->class}' does not exist or does not implement JobInterface.");
            }

            /** @var JobInterface $job */
            $job = $reserved->class::fromPayload($reserved->payload);
            $job->handle($this->container);

            $this->queue->delete($reserved);
            return true;
        } catch (Throwable $e) {
            $final = $job === null || $reserved->attempts >= $job->maxAttempts();

            $this->logger->error('Queue job {class} #{id} failed (attempt {attempt}{final}): {message}', [
                'class'     => $reserved->class,
                'id'        => $reserved->id,
                'attempt'   => $reserved->attempts,
                'final'     => $final ? ', giving up' : '',
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);

            try {
                $this->queue->release($reserved, $e::class . ': ' . $e->getMessage(), $job?->backoff($reserved->attempts) ?? 0, $final);
            } catch (Throwable $releaseError) {
                // The reservation expires after retry_after, so the job is not lost
                $this->logger->error('Queue job #{id} could not be released: {message}', ['id' => $reserved->id, 'message' => $releaseError->getMessage()]);
            }

            return false;
        } finally {
            if ($this->logger instanceof Logger) {
                $this->logger->flush();
            }
        }
    }

    /**
     * @brief Requests a graceful stop after the current job.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * @brief Registers SIGTERM/SIGINT handlers when ext-pcntl is available.
     *
     * @return void
     */
    protected function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());
    }
}
