<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Config\Config;
use Core\Exceptions\PushException;
use Core\Logger\LoggerInterface;

/**
 * @file PushManager.php
 * @brief High-level Web Push API used by applications.
 */

/**
 * @class PushManager
 * @brief Sends notifications to users or all subscribers and keeps the subscription table clean.
 *
 * Inject it into controllers/services:
 * @code
 * public function __construct(private PushManager $push) {}
 * $this->push->sendToUser($userId, new PushMessage('Door opened', 'Front door at 21:04', url: '/events'));
 * @endcode
 * Expired subscriptions (404/410) are deleted and successful deliveries recorded in one query each.
 */
class PushManager
{
    /**
     * @var WebPushSender|null Lazily created sender (VAPID keys are only needed when sending).
     */
    protected ?WebPushSender $sender = null;

    /**
     * @brief PushManager constructor.
     *
     * @param SubscriptionRepository $subscriptions Subscription storage.
     * @param LoggerInterface $logger Logger for delivery failures.
     */
    public function __construct(
        protected SubscriptionRepository $subscriptions,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @brief Checks whether Web Push is enabled in configuration.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Config::get('push.enabled', false);
    }

    /**
     * @brief Returns the configured VAPID public key (browser applicationServerKey).
     *
     * @return string
     */
    public static function publicKey(): string
    {
        return (string)Config::get('push.vapid.public_key', '');
    }

    /**
     * @brief Returns the allowed push service host patterns.
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        return array_values(array_map('strval', (array)Config::get('push.allowed_hosts', [])));
    }

    /**
     * @brief Sends a notification to all subscriptions of one user.
     *
     * @param int|string $userId Recipient user id.
     * @param PushMessage $message Notification.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function sendToUser(int|string $userId, PushMessage $message): array
    {
        return $this->sendToUsers([$userId], $message);
    }

    /**
     * @brief Sends a notification to all subscriptions of several users.
     *
     * @param list<int|string> $userIds Recipient user ids.
     * @param PushMessage $message Notification.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function sendToUsers(array $userIds, PushMessage $message): array
    {
        return $this->deliver($this->subscriptions->findByUsers($userIds), $message);
    }

    /**
     * @brief Sends a notification to every stored subscription (processed in batches).
     *
     * @param PushMessage $message Notification.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function broadcast(PushMessage $message): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'expired' => 0];

        foreach ($this->subscriptions->batches() as $batch) {
            foreach ($this->deliver($batch, $message) as $key => $count) {
                $summary[$key] += $count;
            }
        }

        return $summary;
    }

    /**
     * @brief Sends a notification to explicit subscriptions and applies the bookkeeping.
     *
     * @param list<Subscription> $subscriptions Recipients.
     * @param PushMessage $message Notification.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function deliver(array $subscriptions, PushMessage $message): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'expired' => 0];
        if ($subscriptions === []) {
            return $summary;
        }

        $delivered = [];
        $expired = [];

        foreach ($this->sender()->send($subscriptions, $message) as $result) {
            $id = $result->subscription->id;

            if ($result->isSuccess()) {
                $summary['sent']++;
                if ($id !== null) {
                    $delivered[] = $id;
                }
            } elseif ($result->isExpired()) {
                $summary['expired']++;
                if ($id !== null) {
                    $expired[] = $id;
                }
            } else {
                $summary['failed']++;
                $this->logger->warning('Web Push delivery failed ({status}): {error}', [
                    'status'          => $result->status,
                    'error'           => $result->error,
                    'subscription_id' => $id,
                    'host'            => parse_url($result->subscription->endpoint, PHP_URL_HOST),
                ]);
            }
        }

        $this->subscriptions->markDelivered($delivered);
        $this->subscriptions->deleteIds($expired);

        return $summary;
    }

    /**
     * @brief Creates the sender from configuration on first use.
     *
     * @return WebPushSender
     * @throws PushException If push is disabled or VAPID keys are missing.
     */
    public function sender(): WebPushSender
    {
        if (!self::isEnabled()) {
            throw new PushException('Web Push is disabled (push.enabled = false).');
        }

        return $this->sender ??= new WebPushSender(
            new Vapid(
                (string)Config::get('push.vapid.subject', ''),
                self::publicKey(),
                (string)Config::get('push.vapid.private_key', '')
            ),
            self::allowedHosts(),
            (int)Config::get('push.ttl', 86400),
            (string)Config::get('push.urgency', 'normal'),
            (int)Config::get('push.concurrency', 10),
            (int)Config::get('push.timeout', 10)
        );
    }
}
