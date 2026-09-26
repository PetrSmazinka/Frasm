<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Exceptions\PushException;

/**
 * @file PushMessage.php
 * @brief Notification content and delivery options.
 */

/**
 * @class PushMessage
 * @brief Describes a notification displayed by public/frasm-sw.js.
 *
 * The JSON payload fields map to Notification options: title, body, icon, badge, image, tag,
 * url (opened on click), data (custom), requireInteraction, silent.
 */
final class PushMessage
{
    /**
     * @var list<string> Urgency values defined by RFC 8030.
     */
    public const URGENCIES = ['very-low', 'low', 'normal', 'high'];

    /**
     * @brief PushMessage constructor.
     *
     * @param string $title Notification title.
     * @param string $body Notification text.
     * @param string|null $url URL opened when the notification is clicked.
     * @param string|null $icon Icon URL.
     * @param string|null $tag Replaces an earlier notification with the same tag.
     * @param array<string, mixed> $data Custom data available to the service worker.
     * @param int|null $ttl Seconds the push service keeps an undelivered message (null = push.ttl).
     * @param string|null $urgency very-low|low|normal|high (null = push.urgency).
     * @param string|null $topic Collapses undelivered messages with the same topic (max 32 base64url chars).
     * @param array<string, mixed> $options Extra Notification options (badge, image, requireInteraction, silent, ...).
     * @throws PushException On invalid urgency or topic.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body = '',
        public readonly ?string $url = null,
        public readonly ?string $icon = null,
        public readonly ?string $tag = null,
        public readonly array $data = [],
        public readonly ?int $ttl = null,
        public readonly ?string $urgency = null,
        public readonly ?string $topic = null,
        public readonly array $options = []
    ) {
        if ($urgency !== null && !in_array($urgency, self::URGENCIES, true)) {
            throw new PushException("Invalid push urgency '{$urgency}'.");
        }
        if ($topic !== null && !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $topic)) {
            throw new PushException('Push topic must be 1-32 base64url characters.');
        }
    }

    /**
     * @brief Serializes the notification into the JSON payload understood by frasm-sw.js.
     *
     * @return string
     * @throws PushException If the payload exceeds the Web Push size limit.
     */
    public function toPayload(): string
    {
        $payload = array_filter(
            $this->options + [
                'title' => $this->title,
                'body'  => $this->body,
                'url'   => $this->url,
                'icon'  => $this->icon,
                'tag'   => $this->tag,
                'data'  => $this->data === [] ? null : $this->data,
            ],
            fn(mixed $value): bool => $value !== null && $value !== ''
        );

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > Crypto::MAX_PAYLOAD_LENGTH) {
            throw new PushException('Push payload is ' . strlen($json) . ' bytes; the limit is ' . Crypto::MAX_PAYLOAD_LENGTH . '.');
        }

        return $json;
    }
}
