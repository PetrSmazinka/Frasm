<?php

declare(strict_types=1);

namespace Core\Push;

use Core\DB\DB;

/**
 * @file SubscriptionRepository.php
 * @brief Persistence of push subscriptions in `frasm_push_subscriptions`.
 */

/**
 * @class SubscriptionRepository
 * @brief Stores, looks up and prunes push subscriptions with set-based SQL (no per-row loops).
 */
class SubscriptionRepository
{
    /**
     * @var string Table name.
     */
    protected const TABLE = 'frasm_push_subscriptions';

    /**
     * @brief Inserts or refreshes a subscription (unique by endpoint) and enforces the per-user limit.
     *
     * An endpoint belongs to one browser profile, so re-subscribing reassigns it to the current user.
     * When a user exceeds $maxPerUser subscriptions, the least recently updated ones are removed.
     *
     * @param Subscription $subscription Validated subscription.
     * @param int|string|null $userId Owning user (null = anonymous).
     * @param string|null $userAgent Client user agent (truncated to 255 characters).
     * @param int $maxPerUser Maximum subscriptions kept per user (0 = unlimited).
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function save(Subscription $subscription, int|string|null $userId, ?string $userAgent, int $maxPerUser = 10): void
    {
        $db = DB::getInstance();

        $db->query(
            'INSERT INTO `' . self::TABLE . '` (`user_id`, `endpoint`, `endpoint_hash`, `p256dh`, `auth`, `user_agent`)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 `user_id` = VALUES(`user_id`), `p256dh` = VALUES(`p256dh`), `auth` = VALUES(`auth`),
                 `user_agent` = VALUES(`user_agent`), `updated_at` = CURRENT_TIMESTAMP',
            [
                $userId,
                $subscription->endpoint,
                $subscription->endpointHash(),
                $subscription->p256dh,
                $subscription->auth,
                $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ]
        );

        if ($userId !== null && $maxPerUser > 0) {
            // Derived table works around MySQL's restriction on selecting from the DELETE target
            $db->query(
                'DELETE FROM `' . self::TABLE . '`
                 WHERE `user_id` = ? AND `id` NOT IN (
                     SELECT `id` FROM (
                         SELECT `id` FROM `' . self::TABLE . '` WHERE `user_id` = ? ORDER BY `updated_at` DESC, `id` DESC LIMIT ?
                     ) AS `keep`
                 )',
                [$userId, $userId, $maxPerUser]
            );
        }
    }

    /**
     * @brief Deletes a subscription by endpoint, optionally only when owned by the given user.
     *
     * @param string $endpoint Endpoint URL.
     * @param int|string|null $userId Required owner; null deletes regardless of owner.
     * @return int Number of deleted rows.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function deleteByEndpoint(string $endpoint, int|string|null $userId = null): int
    {
        $hash = hash('sha256', $endpoint);

        return $userId === null
            ? DB::getInstance()->delete(self::TABLE, '`endpoint_hash` = ?', [$hash])
            : DB::getInstance()->delete(self::TABLE, '`endpoint_hash` = ? AND `user_id` = ?', [$hash, $userId]);
    }

    /**
     * @brief Loads all subscriptions of the given users.
     *
     * @param list<int|string> $userIds User ids.
     * @return list<Subscription>
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function findByUsers(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $rows = DB::getInstance()->select(
            'SELECT `id`, `user_id`, `endpoint`, `p256dh`, `auth` FROM `' . self::TABLE . "` WHERE `user_id` IN ({$placeholders})",
            $userIds
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @brief Iterates over all subscriptions in id order using keyset pagination.
     *
     * @param int $batchSize Rows per batch.
     * @return \Generator<int, list<Subscription>> Batches of subscriptions.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function batches(int $batchSize = 500): \Generator
    {
        $lastId = 0;

        do {
            $rows = DB::getInstance()->select(
                'SELECT `id`, `user_id`, `endpoint`, `p256dh`, `auth` FROM `' . self::TABLE . '`
                 WHERE `id` > ? ORDER BY `id` LIMIT ?',
                [$lastId, $batchSize]
            );

            if ($rows !== []) {
                $lastId = (int)$rows[count($rows) - 1]['id'];
                yield array_map([$this, 'hydrate'], $rows);
            }
        } while (count($rows) === $batchSize);
    }

    /**
     * @brief Deletes subscriptions by id in a single statement.
     *
     * @param list<int> $ids Subscription ids.
     * @return int Number of deleted rows.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function deleteIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return DB::getInstance()->delete(self::TABLE, "`id` IN ({$placeholders})", array_values($ids));
    }

    /**
     * @brief Records a successful delivery for the given subscriptions in a single statement.
     *
     * @param list<int> $ids Subscription ids.
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function markDelivered(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        DB::getInstance()->query(
            'UPDATE `' . self::TABLE . "` SET `last_success_at` = NOW() WHERE `id` IN ({$placeholders})",
            array_values($ids)
        );
    }

    /**
     * @brief Converts a database row into a Subscription.
     *
     * @param array<string, mixed> $row Row data.
     * @return Subscription
     */
    protected function hydrate(array $row): Subscription
    {
        return new Subscription(
            (string)$row['endpoint'],
            (string)$row['p256dh'],
            (string)$row['auth'],
            (int)$row['id'],
            $row['user_id'] === null ? null : (int)$row['user_id']
        );
    }
}
