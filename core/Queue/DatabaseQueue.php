<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\DB\DB;
use Core\Exceptions\CoreException;

/**
 * @file DatabaseQueue.php
 * @brief Job queue stored in the `frasm_jobs` table.
 */

/**
 * @class DatabaseQueue
 * @brief MySQL/MariaDB backed queue with safe concurrent reservation.
 *
 * Reservation uses `SELECT ... FOR UPDATE SKIP LOCKED` (MariaDB 10.6+, MySQL 8.0+) inside a short
 * transaction, so parallel workers never receive the same job. Jobs whose worker died are reclaimed
 * after `retry_after` seconds. Permanently failed jobs stay in the table (failed_at set) for inspection.
 */
class DatabaseQueue implements QueueInterface
{
    /**
     * @var string Table name.
     */
    protected const TABLE = 'frasm_jobs';

    /**
     * @brief DatabaseQueue constructor.
     *
     * @param int $retryAfter Seconds after which a reserved but unfinished job is handed out again.
     */
    public function __construct(protected int $retryAfter = 300)
    {
    }

    /**
     * @brief Enqueues a job.
     *
     * @param JobInterface $job Job to run.
     * @param int $delay Seconds before the job becomes available.
     * @param string $queue Queue name.
     * @return int Job id.
     * @throws CoreException On an invalid queue name.
     * @throws \JsonException If the payload is not JSON serializable.
     */
    public function push(JobInterface $job, int $delay = 0, string $queue = 'default'): int
    {
        self::assertQueueName($queue);

        DB::getInstance()->query(
            'INSERT INTO `' . self::TABLE . '` (`queue`, `job_class`, `payload`, `max_attempts`, `available_at`)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ? SECOND)',
            [
                $queue,
                $job::class,
                json_encode($job->toPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                max(1, $job->maxAttempts()),
                max(0, $delay),
            ]
        );

        return (int)DB::getInstance()->lastInsertId();
    }

    /**
     * @brief Reserves up to $limit available jobs in FIFO order.
     *
     * @param string $queue Queue name.
     * @param int $limit Maximum number of jobs.
     * @return list<ReservedJob>
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function reserve(string $queue = 'default', int $limit = 10): array
    {
        $db = DB::getInstance();

        return $db->transactional(function (DB $db) use ($queue, $limit): array {
            $rows = $db->select(
                'SELECT `id`, `queue`, `job_class`, `payload`, `attempts`
                 FROM `' . self::TABLE . '`
                 WHERE `queue` = ? AND `failed_at` IS NULL AND `available_at` <= NOW()
                   AND (`reserved_at` IS NULL OR `reserved_at` < NOW() - INTERVAL ? SECOND)
                 ORDER BY `id`
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED',
                [$queue, $this->retryAfter, max(1, $limit)]
            );

            if ($rows === []) {
                return [];
            }

            $ids = array_map(fn(array $row): int => (int)$row['id'], $rows);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $db->query(
                'UPDATE `' . self::TABLE . "` SET `reserved_at` = NOW(), `attempts` = `attempts` + 1 WHERE `id` IN ({$placeholders})",
                $ids
            );

            $jobs = [];
            foreach ($rows as $row) {
                $payload = json_decode((string)$row['payload'], true);
                $jobs[] = new ReservedJob(
                    (int)$row['id'],
                    (string)$row['queue'],
                    (string)$row['job_class'],
                    is_array($payload) ? $payload : [],
                    (int)$row['attempts'] + 1
                );
            }

            return $jobs;
        });
    }

    /**
     * @brief Removes a successfully processed job.
     *
     * @param ReservedJob $job Reserved job.
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function delete(ReservedJob $job): void
    {
        DB::getInstance()->delete(self::TABLE, '`id` = ?', [$job->id]);
    }

    /**
     * @brief Schedules a retry or marks the job as permanently failed.
     *
     * @param ReservedJob $job Reserved job.
     * @param string $error Failure description (truncated to 2000 characters).
     * @param int $delay Seconds before the retry.
     * @param bool $fail Mark the job as permanently failed.
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function release(ReservedJob $job, string $error, int $delay, bool $fail): void
    {
        DB::getInstance()->query(
            'UPDATE `' . self::TABLE . '`
             SET `reserved_at` = NULL, `last_error` = ?,
                 `available_at` = NOW() + INTERVAL ? SECOND,
                 `failed_at` = IF(?, NOW(), NULL)
             WHERE `id` = ?',
            [mb_substr($error, 0, 2000), max(0, $delay), $fail ? 1 : 0, $job->id]
        );
    }

    /**
     * @brief Returns counts of pending, reserved and failed jobs per queue.
     *
     * @return list<array{queue: string, pending: int, reserved: int, failed: int}>
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function stats(): array
    {
        $rows = DB::getInstance()->select(
            'SELECT `queue`,
                    SUM(`failed_at` IS NULL AND (`reserved_at` IS NULL OR `reserved_at` < NOW() - INTERVAL ? SECOND)) AS `pending`,
                    SUM(`failed_at` IS NULL AND `reserved_at` >= NOW() - INTERVAL ? SECOND) AS `reserved`,
                    SUM(`failed_at` IS NOT NULL) AS `failed`
             FROM `' . self::TABLE . '` GROUP BY `queue` ORDER BY `queue`',
            [$this->retryAfter, $this->retryAfter]
        );

        return array_map(fn(array $row): array => [
            'queue'    => (string)$row['queue'],
            'pending'  => (int)$row['pending'],
            'reserved' => (int)$row['reserved'],
            'failed'   => (int)$row['failed'],
        ], $rows);
    }

    /**
     * @brief Lists permanently failed jobs.
     *
     * @param int $limit Maximum number of rows.
     * @return list<array<string, mixed>>
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function failed(int $limit = 50): array
    {
        return DB::getInstance()->select(
            'SELECT `id`, `queue`, `job_class`, `attempts`, `failed_at`, `last_error`
             FROM `' . self::TABLE . '` WHERE `failed_at` IS NOT NULL ORDER BY `failed_at` DESC LIMIT ?',
            [$limit]
        );
    }

    /**
     * @brief Moves failed jobs back to the queue with a fresh attempt counter.
     *
     * @param int|null $id Job id, or null for all failed jobs.
     * @return int Number of re-queued jobs.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function retryFailed(?int $id = null): int
    {
        $sql = 'UPDATE `' . self::TABLE . '` SET `failed_at` = NULL, `attempts` = 0, `available_at` = NOW(), `reserved_at` = NULL
                WHERE `failed_at` IS NOT NULL';
        $params = [];
        if ($id !== null) {
            $sql .= ' AND `id` = ?';
            $params[] = $id;
        }

        DB::getInstance()->query($sql, $params);
        return DB::getInstance()->affectedRows();
    }

    /**
     * @brief Deletes failed jobs.
     *
     * @return int Number of deleted rows.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function flushFailed(): int
    {
        return DB::getInstance()->delete(self::TABLE, '`failed_at` IS NOT NULL');
    }

    /**
     * @brief Validates a queue name.
     *
     * @param string $queue Queue name.
     * @return void
     * @throws CoreException If the name is invalid.
     */
    public static function assertQueueName(string $queue): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $queue)) {
            throw new CoreException("Invalid queue name '{$queue}'.");
        }
    }
}
