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
     * @param string|null $app Required app (pwa.apps key, '' = main app, null = any app).
     * @throws PushException On an invalid channel or app name.
     */
    private function __construct(
        public readonly ?array $userIds,
        public readonly ?string $channel,
        public readonly ?string $app = null
    ) {
        if ($channel !== null) {
            self::assertChannel($channel);
        }
        if ($app !== null) {
            self::assertApp($app);
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
        return new self($this->userIds, $channel, $this->app);
    }

    /**
     * @brief Restricts the target to subscriptions made in one installable app (config pwa.apps).
     *
     * Each app registers the service worker with its own scope, so its subscriptions are separate
     * and their notifications belong to that app (Android). Notifications about a part of the site
     * should go only to the app of that part.
     *
     * @param string $app App name, '' for the main app.
     * @return self
     * @throws PushException On an invalid app name.
     */
    public function inApp(string $app): self
    {
        return new self($this->userIds, $this->channel, $app);
    }

    /**
     * @brief Serializes the target (for the job queue).
     *
     * @return array{users: list<int>|null, channel: string|null, app: string|null}
     */
    public function toArray(): array
    {
        return ['users' => $this->userIds, 'channel' => $this->channel, 'app' => $this->app];
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
        return new self(
            $users,
            isset($data['channel']) ? (string)$data['channel'] : null,
            isset($data['app']) ? (string)$data['app'] : null
        );
    }

    /**
     * @brief Validates an app name ('' = main app).
     * @param string $app App name.
     * @return void
     * @throws PushException When invalid.
     */
    public static function assertApp(string $app): void
    {
        if ($app !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $app)) {
            throw new PushException("Invalid app name '{$app}'.", 400);
        }
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
