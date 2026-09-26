<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Exceptions\PushException;

/**
 * @file PushTarget.php
 * @brief Recipient selection for push messages.
 */

/**
 * @class PushTarget
 * @brief Selects subscriptions by user ids and/or channel.
 *
 * Examples: PushTarget::user(5), PushTarget::users([1, 2]), PushTarget::all(),
 * PushTarget::channel('alarms'), PushTarget::users([1, 2])->inChannel('reports').
 * A channel is a category the subscriber opted into (e.g. "alarms", "daily reports").
 */
final class PushTarget
{
    /**
     * @brief PushTarget constructor.
     *
     * @param list<int>|null $userIds Recipient users (null = every subscription).
     * @param string|null $channel Required channel (null = no channel filter).
     * @throws PushException On an invalid channel name.
     */
    private function __construct(
        public readonly ?array $userIds,
        public readonly ?string $channel
    ) {
        if ($channel !== null) {
            self::assertChannel($channel);
        }
    }

    /**
     * @brief Targets all subscriptions of one user.
     *
     * @param int $userId User id.
     * @return self
     */
    public static function user(int $userId): self
    {
        return new self([$userId], null);
    }

    /**
     * @brief Targets all subscriptions of several users.
     *
     * @param list<int> $userIds User ids.
     * @return self
     */
    public static function users(array $userIds): self
    {
        return new self(array_values(array_unique(array_map('intval', $userIds))), null);
    }

    /**
     * @brief Targets every subscription.
     *
     * @return self
     */
    public static function all(): self
    {
        return new self(null, null);
    }

    /**
     * @brief Targets every subscription that opted into a channel.
     *
     * @param string $channel Channel name.
     * @return self
     */
    public static function channel(string $channel): self
    {
        return new self(null, $channel);
    }

    /**
     * @brief Restricts the target to subscriptions that opted into a channel.
     *
     * @param string $channel Channel name.
     * @return self
     */
    public function inChannel(string $channel): self
    {
        return new self($this->userIds, $channel);
    }

    /**
     * @brief Serializes the target (for the job queue).
     *
     * @return array{users: list<int>|null, channel: string|null}
     */
    public function toArray(): array
    {
        return ['users' => $this->userIds, 'channel' => $this->channel];
    }

    /**
     * @brief Restores a target from toArray() output.
     *
     * @param array<string, mixed> $data Serialized target.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $users = isset($data['users']) && is_array($data['users']) ? array_values(array_map('intval', $data['users'])) : null;
        return new self($users, isset($data['channel']) ? (string)$data['channel'] : null);
    }

    /**
     * @brief Validates a channel name.
     *
     * @param string $channel Channel name.
     * @return void
     * @throws PushException When invalid.
     */
    public static function assertChannel(string $channel): void
    {
        if (!preg_match('/^[a-z0-9_.-]{1,64}$/D', $channel)) {
            throw new PushException("Invalid push channel '{$channel}' (allowed: a-z, 0-9, '_', '.', '-').", 400);
        }
    }
}
