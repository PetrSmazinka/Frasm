<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Config\Config;
use Core\Exceptions\PushException;
use Core\Logger\LoggerInterface;
use Core\Queue\QueueInterface;

/**
 * @file PushManager.php
 * @brief High-level Web Push API used by applications.
 */

/**
 * @class PushManager
 * @brief Sends notifications synchronously or through the job queue and keeps subscriptions clean.
 *
 * @code
 * public function __construct(private PushManager $push) {}
 *
 * // Immediately, inside the request (alarms, a few recipients)
 * $this->push->send(new PushMessage('Alarm', 'Motion in the garage', url: '/cameras'), PushTarget::user($id));
 *
 * // Asynchronously via the queue worker (broadcasts, anything that may take long)
 * $this->push->queue(new PushMessage('Daily report', ttl: 3600), PushTarget::channel('reports'));
 * @endcode
 *
 * Expired subscriptions (404/410) are deleted and successful deliveries recorded with one query per batch.
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
     * @param QueueInterface $queue Job queue for asynchronous delivery.
     */
    public function __construct(
        protected SubscriptionRepository $subscriptions,
        protected LoggerInterface $logger,
        protected QueueInterface $queue
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
     * @brief Returns the configured channels (name => label); empty means any valid channel name is accepted.
     *
     * @return array<string, string>
     */
    public static function channels(): array
    {
        return array_map('strval', (array)Config::get('push.channels', []));
    }

    /**
     * @brief Validates channel names requested by a client against the configuration.
     *
     * @param mixed $channels Client supplied value.
     * @return list<string>
     * @throws PushException 400 on invalid or unknown channels.
     */
    public static function validateChannels(mixed $channels): array
    {
        if (!is_array($channels)) {
            throw new PushException('Push channels must be a list of names.', 400);
        }

        $configured = self::channels();
        $valid = [];

        foreach ($channels as $channel) {
            if (!is_string($channel)) {
                throw new PushException('Push channel names must be strings.', 400);
            }
            PushTarget::assertChannel($channel);
            if ($configured !== [] && !array_key_exists($channel, $configured)) {
                throw new PushException("Unknown push channel '{$channel}'.", 400);
            }
            $valid[] = $channel;
        }

        return array_values(array_unique($valid));
    }

    /**
     * @brief Validates the app a subscription is made in: '' (main app) or a key of pwa.apps.
     *
     * @param mixed $app Submitted value.
     * @return string
     * @throws PushException 400 for unknown apps.
     */
    public static function validateApp(mixed $app): string
    {
        $app = is_string($app) ? $app : '';
        if ($app === '') {
            return '';
        }
        PushTarget::assertApp($app);
        if (!array_key_exists($app, (array)Config::get('pwa.apps', []))) {
            throw new PushException("Unknown app '{$app}'.", 400);
        }

        return $app;
    }

    /**
     * @brief Sends a message immediately (inside the current request).
     *
     * @param PushMessage $message Notification.
     * @param PushTarget|Subscription|list<Subscription> $target Recipients.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function send(PushMessage $message, PushTarget|Subscription|array $target): array
    {
        if (!$target instanceof PushTarget) {
            return $this->deliver($target instanceof Subscription ? [$target] : array_values($target), $message);
        }

        $summary = ['sent' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($this->subscriptions->batches($target) as $batch) {
            foreach ($this->deliver($batch, $message) as $key => $count) {
                $summary[$key] += $count;
            }
        }

        return $summary;
    }

    /**
     * @brief Alias of send() emphasizing synchronous delivery.
     *
     * @param PushMessage $message Notification.
     * @param PushTarget|Subscription|list<Subscription> $target Recipients.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function sendNow(PushMessage $message, PushTarget|Subscription|array $target): array
    {
        return $this->send($message, $target);
    }

    /**
     * @brief Enqueues a message for the queue worker (returns within milliseconds).
     *
     * The message TTL counts from the moment the message becomes available (now + delay):
     * if the worker picks it up later than that, it is dropped.
     *
     * @param PushMessage $message Notification.
     * @param PushTarget $target Recipients.
     * @param int $delay Seconds before the message may be sent.
     * @return int Job id.
     * @throws PushException If push is disabled.
     */
    public function queue(PushMessage $message, PushTarget $target, int $delay = 0): int
    {
        if (!self::isEnabled()) {
            throw new PushException('Web Push is disabled (push.enabled = false).');
        }

        // Fail fast on payload problems instead of inside the worker
        $message->toPayload();

        return $this->queue->push(
            new SendPushJob($message, $target, time() + max(0, $delay)),
            $delay,
            (string)Config::get('push.queue', 'default')
        );
    }

    /**
     * @brief Sends a notification to all subscriptions of one user (synchronously).
     *
     * @param int $userId Recipient user id.
     * @param PushMessage $message Notification.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function sendToUser(int $userId, PushMessage $message): array
    {
        return $this->send($message, PushTarget::user($userId));
    }

    /**
     * @brief Sends a notification to all subscriptions of several users (synchronously).
     *
     * @param list<int> $userIds Recipient user ids.
     * @param PushMessage $message Notification.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function sendToUsers(array $userIds, PushMessage $message): array
    {
        return $this->send($message, PushTarget::users($userIds));
    }

    /**
     * @brief Sends a notification to every subscription, optionally of one channel (synchronously).
     *
     * @param PushMessage $message Notification.
     * @param string|null $channel Channel filter.
     * @return array{sent: int, failed: int, expired: int} Delivery summary.
     * @throws PushException If push is disabled or misconfigured.
     */
    public function broadcast(PushMessage $message, ?string $channel = null): array
    {
        return $this->send($message, $channel === null ? PushTarget::all() : PushTarget::channel($channel));
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
