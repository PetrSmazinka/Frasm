<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Core\DB\DB;

/**
 * @file TaskStore.php
 * @brief Persistent state and locks of scheduled tasks (table `frasm_scheduled_tasks`).
 */

/**
 * @class TaskStore
 * @brief Atomically claims tasks (lock + due check in one UPDATE) and records their results.
 *
 * A claim succeeds only when the task is not locked by another run (or its lock expired after a
 * crash) and, for scheduled runs, when it is due. Parallel cron runners and a manual HTTP call can
 * therefore never execute the same task twice at once. Unchanged minutes cause no disk writes.
 */
class TaskStore
{
    /**
     * @var string Table name.
     */
    protected const TABLE = 'frasm_scheduled_tasks';

    /**
     * @var list<string> Recorded trigger types.
     */
    public const TRIGGERS = ['schedule', 'http', 'cli'];

    /**
     * @brief Claims a task.
     *
     * @param string $task Task key.
     * @param int $lockSeconds Lock lifetime (protects against crashed runs).
     * @param string $trigger 'schedule', 'http' or 'cli'.
     * @param int|null $minIntervalSeconds Only claim when the last start is at least this old (interval schedules).
     * @param string|null $notStartedSince Only claim when the task has not started since this time (Y-m-d H:i:s; cron schedules).
     * @return bool True when this caller may run the task now.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function claim(string $task, int $lockSeconds, string $trigger, ?int $minIntervalSeconds = null, ?string $notStartedSince = null): bool
    {
        $db = DB::getInstance();
        $db->query('INSERT IGNORE INTO `' . self::TABLE . '` (`task`) VALUES (?)', [$task]);

        $sql = 'UPDATE `' . self::TABLE . '`
                SET `locked_until` = NOW() + INTERVAL ? SECOND, `last_started_at` = NOW(), `last_trigger` = ?
                WHERE `task` = ? AND (`locked_until` IS NULL OR `locked_until` < NOW())';
        $params = [max(1, $lockSeconds), $trigger, $task];

        if ($minIntervalSeconds !== null) {
            $sql .= ' AND (`last_started_at` IS NULL OR `last_started_at` <= NOW() - INTERVAL ? SECOND)';
            $params[] = max(0, $minIntervalSeconds);
        }
        if ($notStartedSince !== null) {
            $sql .= ' AND (`last_started_at` IS NULL OR `last_started_at` < ?)';
            $params[] = $notStartedSince;
        }

        $db->query($sql, $params);

        return $db->affectedRows() === 1;
    }

    /**
     * @brief Releases the lock and records the outcome of a run.
     *
     * @param string $task Task key.
     * @param bool $success Whether the run succeeded.
     * @param string|null $error Error message of a failed run.
     * @param int $durationMs Run time in milliseconds.
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function finish(string $task, bool $success, ?string $error, int $durationMs): void
    {
        DB::getInstance()->query(
            'UPDATE `' . self::TABLE . '`
             SET `locked_until` = NULL, `last_finished_at` = NOW(), `last_status` = ?, `last_error` = ?, `last_duration_ms` = ?
             WHERE `task` = ?',
            [$success ? 'success' : 'failed', $error === null ? null : mb_substr($error, 0, 2000), max(0, $durationMs), $task]
        );
    }

    /**
     * @brief Returns the state of one task.
     *
     * @param string $task Task key.
     * @return array{task: string, locked_until: ?string, last_started_at: ?string, last_finished_at: ?string, last_status: ?string, last_error: ?string, last_duration_ms: ?int, last_trigger: ?string}|null
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function state(string $task): ?array
    {
        $row = DB::getInstance()->selectOne('SELECT * FROM `' . self::TABLE . '` WHERE `task` = ? LIMIT 1', [$task]);
        if ($row === null) {
            return null;
        }

        $row['last_duration_ms'] = $row['last_duration_ms'] === null ? null : (int)$row['last_duration_ms'];
        return $row;
    }

    /**
     * @brief Returns the state of all tasks keyed by task.
     *
     * @return array<string, array<string, mixed>>
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function all(): array
    {
        $states = [];
        foreach (DB::getInstance()->select('SELECT * FROM `' . self::TABLE . '`') as $row) {
            $states[(string)$row['task']] = $row;
        }

        return $states;
    }
}
