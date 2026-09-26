<?php

declare(strict_types=1);

namespace Core\RateLimit;

use Core\DB\DB;

/**
 * @file RateLimiter.php
 * @brief Database-backed fixed-window rate limiter.
 */

/**
 * @class RateLimiter
 * @brief Counts hits per key within a fixed time window using the `frasm_rate_limits` table.
 *
 * Each hit is a single atomic `INSERT ... ON DUPLICATE KEY UPDATE`, so concurrent requests
 * (multiple Apache workers) never lose increments. An expired window restarts at 1 on the next hit.
 * Keys are stored as SHA-256 hashes. Expired rows are garbage-collected probabilistically.
 */
class RateLimiter
{
    /**
     * @brief RateLimiter constructor.
     *
     * @param int $pruneProbability One-in-N chance that a hit also deletes expired rows (0 disables).
     */
    public function __construct(protected int $pruneProbability = 100)
    {
    }

    /**
     * @brief Registers a hit for the key and returns the updated counter state.
     *
     * @param string $key Limiter key (e.g. "login|203.0.113.7").
     * @param int $decaySeconds Window length in seconds.
     * @return array{hits: int, retry_after: int} Hits in the current window and seconds until it resets.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function hit(string $key, int $decaySeconds): array
    {
        $decaySeconds = max(1, $decaySeconds);
        $db = DB::getInstance();
        $hash = $this->hashKey($key);

        // Column assignments are evaluated left to right, so `expires_at` in the second IF still sees the old value.
        $db->query(
            'INSERT INTO `frasm_rate_limits` (`key_hash`, `hits`, `expires_at`)
             VALUES (?, 1, NOW() + INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE
                 `hits` = IF(`expires_at` <= NOW(), 1, `hits` + 1),
                 `expires_at` = IF(`expires_at` <= NOW(), NOW() + INTERVAL ? SECOND, `expires_at`)',
            [$hash, $decaySeconds, $decaySeconds]
        );

        $this->maybePrune();

        $row = $db->selectOne(
            'SELECT `hits`, GREATEST(TIMESTAMPDIFF(SECOND, NOW(), `expires_at`), 0) AS `retry_after`
             FROM `frasm_rate_limits` WHERE `key_hash` = ? LIMIT 1',
            [$hash]
        );

        return [
            'hits'        => (int)($row['hits'] ?? 1),
            'retry_after' => (int)($row['retry_after'] ?? $decaySeconds),
        ];
    }

    /**
     * @brief Returns the number of hits in the current (non-expired) window.
     *
     * @param string $key Limiter key.
     * @return int
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function attempts(string $key): int
    {
        return (int)DB::getInstance()->selectValue(
            'SELECT `hits` FROM `frasm_rate_limits` WHERE `key_hash` = ? AND `expires_at` > NOW() LIMIT 1',
            [$this->hashKey($key)]
        );
    }

    /**
     * @brief Checks whether the key has reached the maximum number of attempts.
     *
     * @param string $key Limiter key.
     * @param int $maxAttempts Allowed attempts per window.
     * @return bool
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * @brief Returns seconds until the current window of the key resets (0 when no active window).
     *
     * @param string $key Limiter key.
     * @return int
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function availableIn(string $key): int
    {
        return (int)DB::getInstance()->selectValue(
            'SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), `expires_at`), 0)
             FROM `frasm_rate_limits` WHERE `key_hash` = ? LIMIT 1',
            [$this->hashKey($key)]
        );
    }

    /**
     * @brief Resets the counter of a key (e.g. after a successful login).
     *
     * @param string $key Limiter key.
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function clear(string $key): void
    {
        DB::getInstance()->delete('frasm_rate_limits', '`key_hash` = ?', [$this->hashKey($key)]);
    }

    /**
     * @brief Deletes all expired counters.
     *
     * @return int Number of deleted rows.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function prune(): int
    {
        return DB::getInstance()->delete('frasm_rate_limits', '`expires_at` <= NOW()');
    }

    /**
     * @brief Runs prune() with the configured probability.
     *
     * @return void
     */
    protected function maybePrune(): void
    {
        if ($this->pruneProbability > 0 && random_int(1, $this->pruneProbability) === 1) {
            $this->prune();
        }
    }

    /**
     * @brief Hashes a limiter key to a fixed-length identifier.
     *
     * @param string $key Limiter key.
     * @return string 64-character hex digest.
     */
    protected function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
