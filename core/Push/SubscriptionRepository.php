<?php

declare(strict_types=1);

namespace Core\Push;

use Core\DB\DB;

/**
 * @file SubscriptionRepository.php
 * @brief Persistence of push subscriptions and their channels.
 */

/**
 * @class SubscriptionRepository
 * @brief Stores, selects and prunes push subscriptions with set-based SQL (no per-row loops).
 *
 * Tables: `frasm_push_subscriptions` and `frasm_push_channels` (subscription ↔ channel, cascaded on delete).
 */
class SubscriptionRepository
{
    /**
     * @var string Subscription table.
     */
    protected const TABLE = 'frasm_push_subscriptions';

    /**
     * @var string Channel membership table.
     */
    protected const CHANNELS = 'frasm_push_channels';

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
     * @return array{id: int, created: bool} Subscription id and whether a new row was inserted.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function save(Subscription $subscription, int|string|null $userId, ?string $userAgent, int $maxPerUser = 10): array
    {
        $db = DB::getInstance();

        // LAST_INSERT_ID(id) makes the existing id available on the duplicate-key path too
        $db->query(
            'INSERT INTO `' . self::TABLE . '` (`user_id`, `endpoint`, `endpoint_hash`, `p256dh`, `auth`, `user_agent`)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 `id` = LAST_INSERT_ID(`id`),
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

        $created = $db->affectedRows() === 1;
        $id = (int)$db->lastInsertId();

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

        return ['id' => $id, 'created' => $created];
    }

    /**
     * @brief Finds the id and owner of a subscription by endpoint.
     *
     * @param string $endpoint Endpoint URL.
     * @return array{id: int, user_id: int|null}|null
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function findByEndpoint(string $endpoint): ?array
    {
        $row = DB::getInstance()->selectOne(
            'SELECT `id`, `user_id` FROM `' . self::TABLE . '` WHERE `endpoint_hash` = ? LIMIT 1',
            [hash('sha256', $endpoint)]
        );

        return $row === null ? null : [
            'id'      => (int)$row['id'],
            'user_id' => $row['user_id'] === null ? null : (int)$row['user_id'],
        ];
    }

    /**
     * @brief Replaces the channel memberships of a subscription.
     *
     * @param int $subscriptionId Subscription id.
     * @param list<string> $channels Validated channel names.
     * @return void
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function setChannels(int $subscriptionId, array $channels): void
    {
        $channels = array_values(array_unique($channels));

        DB::getInstance()->transactional(function (DB $db) use ($subscriptionId, $channels): void {
            $db->delete(self::CHANNELS, '`subscription_id` = ?', [$subscriptionId]);

            if ($channels === []) {
                return;
            }

            $values = implode(', ', array_fill(0, count($channels), '(?, ?)'));
            $params = [];
            foreach ($channels as $channel) {
                $params[] = $subscriptionId;
                $params[] = $channel;
            }

            $db->query('INSERT INTO `' . self::CHANNELS . "` (`subscription_id`, `channel`) VALUES {$values}", $params);
        });
    }

    /**
     * @brief Returns the channels a subscription opted into.
     *
     * @param int $subscriptionId Subscription id.
     * @return list<string>
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function channelsOf(int $subscriptionId): array
    {
        return array_map('strval', array_column(DB::getInstance()->select(
            'SELECT `channel` FROM `' . self::CHANNELS . '` WHERE `subscription_id` = ? ORDER BY `channel`',
            [$subscriptionId]
        ), 'channel'));
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
     * @brief Iterates over the subscriptions selected by a target, in id order (keyset pagination).
     *
     * @param PushTarget $target Recipients.
     * @param int $batchSize Rows per batch.
     * @return \Generator<int, list<Subscription>> Batches of subscriptions.
     * @throws \Core\Exceptions\DatabaseException On query failure.
     */
    public function batches(PushTarget $target, int $batchSize = 500): \Generator
    {
        if ($target->userIds === []) {
            return;
        }

        $join = '';
        $conditions = ['s.`id` > ?'];
        $fixedParams = [];

        if ($target->channel !== null) {
            $join = 'INNER JOIN `' . self::CHANNELS . '` c ON c.`subscription_id` = s.`id` AND c.`channel` = ?';
            $fixedParams[] = $target->channel;
        }

        if ($target->userIds !== null) {
            $conditions[] = 's.`user_id` IN (' . implode(', ', array_fill(0, count($target->userIds), '?')) . ')';
        }

        $sql = 'SELECT s.`id`, s.`user_id`, s.`endpoint`, s.`p256dh`, s.`auth`
                FROM `' . self::TABLE . "` s {$join}
                WHERE " . implode(' AND ', $conditions) . '
                ORDER BY s.`id` LIMIT ?';

        $lastId = 0;
        do {
            $params = array_merge($fixedParams, [$lastId], $target->userIds ?? [], [$batchSize]);
            $rows = DB::getInstance()->select($sql, $params);

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
