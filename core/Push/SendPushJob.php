<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Container\Container;
use Core\Logger\LoggerInterface;
use Core\Queue\Job;

/**
 * @file SendPushJob.php
 * @brief Queue job delivering a push message asynchronously.
 */

/**
 * @class SendPushJob
 * @brief Created by PushManager::queue(); executed by the queue worker.
 *
 * The message TTL is counted from the moment of queueing: a message whose TTL elapsed while
 * waiting in the queue is dropped, and the remaining TTL is passed to the push service.
 * Per-subscription delivery failures do not retry the job (that would duplicate notifications
 * for the successful recipients); only systemic failures (database, configuration) do.
 */
class SendPushJob extends Job
{
    /**
     * @brief SendPushJob constructor.
     *
     * @param PushMessage $message Notification.
     * @param PushTarget $target Recipients.
     * @param int $queuedAt Unix time of queueing.
     */
    public function __construct(
        public readonly PushMessage $message,
        public readonly PushTarget $target,
        public readonly int $queuedAt
    ) {
    }

    /**
     * @brief Sends the message unless its TTL has already elapsed.
     *
     * @param Container $container Service container.
     * @return void
     * @throws \Throwable On systemic failures (the job is retried).
     */
    public function handle(Container $container): void
    {
        $message = $this->message;

        if ($message->ttl !== null) {
            $remaining = $message->ttl - (time() - $this->queuedAt);
            if ($remaining <= 0) {
                $container->get(LoggerInterface::class)->info('Queued push "{title}" dropped: TTL elapsed before delivery.', [
                    'title' => $message->title,
                ]);
                return;
            }
            $message = $message->withTtl($remaining);
        }

        $container->get(PushManager::class)->send($message, $this->target);
    }

    /**
     * @brief Returns the serializable job data.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'message'   => $this->message->toArray(),
            'target'    => $this->target->toArray(),
            'queued_at' => $this->queuedAt,
        ];
    }

    /**
     * @brief Rebuilds the job from its payload.
     *
     * @param array<string, mixed> $payload Job data.
     * @return static
     */
    public static function fromPayload(array $payload): static
    {
        return new static(
            PushMessage::fromArray((array)($payload['message'] ?? [])),
            PushTarget::fromArray((array)($payload['target'] ?? [])),
            (int)($payload['queued_at'] ?? time())
        );
    }
}
