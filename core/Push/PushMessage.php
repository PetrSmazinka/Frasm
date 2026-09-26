<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Config\Config;
use Core\Exceptions\PushException;

/**
 * @file PushMessage.php
 * @brief Notification content and delivery options.
 */

/**
 * @class PushMessage
 * @brief Describes a notification handled by public/frasm-sw.js.
 *
 * Payload fields: title, body, url (opened on click), icon, tag, data (custom), actions (buttons),
 * appBadge (number on the installed app icon), dataOnly (deliver data to open tabs, see below) and
 * any extra Notification option via $options (badge, image, requireInteraction, silent, ...).
 *
 * Action buttons: ['action' => 'close', 'title' => 'Zavřít', 'url' => '/garage'] opens a page,
 * ['action' => 'close', 'title' => 'Zavřít', 'post' => '/api/garage/close'] sends a background POST
 * from the service worker, authorized by a signed PushAction token instead of a CSRF token.
 *
 * Data-only messages are forwarded to open tabs (`frasm:push` event); browsers require every push
 * to be user visible, so a notification with the title is still shown when no tab is visible.
 */
final class PushMessage
{
    /**
     * @var list<string> Urgency values defined by RFC 8030.
     */
    public const URGENCIES = ['very-low', 'low', 'normal', 'high'];

    /**
     * @var int Maximum number of action buttons (browsers display at most 2 today).
     */
    public const MAX_ACTIONS = 4;

    /**
     * @brief PushMessage constructor.
     *
     * @param string $title Notification title.
     * @param string $body Notification text.
     * @param string|null $url URL opened when the notification is clicked.
     * @param string|null $icon Icon URL.
     * @param string|null $tag Replaces an earlier notification with the same tag.
     * @param array<string, mixed> $data Custom data available to the service worker and tabs.
     * @param int|null $ttl Seconds the push service keeps an undelivered message (null = push.ttl);
     *                      queued messages older than this are dropped before sending.
     * @param string|null $urgency very-low|low|normal|high (null = push.urgency).
     * @param string|null $topic RFC 8030 collapse key: a newer undelivered message with the same topic replaces the older one.
     * @param array<string, mixed> $options Extra Notification options (badge, image, requireInteraction, silent, ...).
     * @param list<array{action: string, title: string, icon?: string, url?: string, post?: string}> $actions Action buttons.
     * @param int|null $appBadge Number shown on the installed app icon (0 clears it).
     * @param bool $dataOnly Forward to open tabs; show a notification only when no tab is visible.
     * @throws PushException On invalid urgency, topic or actions.
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
        public readonly array $options = [],
        public readonly array $actions = [],
        public readonly ?int $appBadge = null,
        public readonly bool $dataOnly = false
    ) {
        if ($urgency !== null && !in_array($urgency, self::URGENCIES, true)) {
            throw new PushException("Invalid push urgency '{$urgency}'.");
        }
        if ($topic !== null && !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $topic)) {
            throw new PushException('Push topic must be 1-32 base64url characters.');
        }
        if ($ttl !== null && $ttl < 0) {
            throw new PushException('Push TTL must not be negative.');
        }

        self::validateActions($actions);
    }

    /**
     * @brief Returns a copy with a different TTL (used when a queued message is sent later).
     *
     * @param int $ttl New TTL in seconds.
     * @return self
     */
    public function withTtl(int $ttl): self
    {
        $data = $this->toArray();
        $data['ttl'] = max(0, $ttl);

        return self::fromArray($data);
    }

    /**
     * @brief Serializes all fields (for the job queue).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title'    => $this->title,
            'body'     => $this->body,
            'url'      => $this->url,
            'icon'     => $this->icon,
            'tag'      => $this->tag,
            'data'     => $this->data,
            'ttl'      => $this->ttl,
            'urgency'  => $this->urgency,
            'topic'    => $this->topic,
            'options'  => $this->options,
            'actions'  => $this->actions,
            'appBadge' => $this->appBadge,
            'dataOnly' => $this->dataOnly,
        ];
    }

    /**
     * @brief Restores a message from toArray() output.
     *
     * @param array<string, mixed> $data Serialized message.
     * @return self
     * @throws PushException On invalid values.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['title'] ?? ''),
            (string)($data['body'] ?? ''),
            isset($data['url']) ? (string)$data['url'] : null,
            isset($data['icon']) ? (string)$data['icon'] : null,
            isset($data['tag']) ? (string)$data['tag'] : null,
            (array)($data['data'] ?? []),
            isset($data['ttl']) ? (int)$data['ttl'] : null,
            isset($data['urgency']) ? (string)$data['urgency'] : null,
            isset($data['topic']) ? (string)$data['topic'] : null,
            (array)($data['options'] ?? []),
            array_values((array)($data['actions'] ?? [])),
            isset($data['appBadge']) ? (int)$data['appBadge'] : null,
            (bool)($data['dataOnly'] ?? false)
        );
    }

    /**
     * @brief Serializes the notification into the JSON payload understood by frasm-sw.js.
     *
     * Background POST actions receive a signed token valid for `push.action_token_ttl` seconds.
     *
     * @return string
     * @throws PushException If the payload exceeds the Web Push size limit.
     * @throws \Core\Exceptions\CoreException If an action needs a token but app.key is missing.
     */
    public function toPayload(): string
    {
        $buttons = [];
        $handlers = [];
        $tokenTtl = (int)Config::get('push.action_token_ttl', 604800);

        foreach ($this->actions as $action) {
            $buttons[] = array_filter([
                'action' => $action['action'],
                'title'  => $action['title'],
                'icon'   => $action['icon'] ?? null,
            ], fn(mixed $value): bool => $value !== null);

            if (isset($action['post'])) {
                $handlers[$action['action']] = [
                    'post'  => $action['post'],
                    'token' => PushAction::token($action['post'], $action['action'], $tokenTtl),
                ];
            } elseif (isset($action['url'])) {
                $handlers[$action['action']] = ['url' => $action['url']];
            }
        }

        $payload = array_filter(
            $this->options + [
                'title'        => $this->title,
                'body'         => $this->body,
                'url'          => $this->url,
                'icon'         => $this->icon,
                'tag'          => $this->tag,
                'data'         => $this->data === [] ? null : $this->data,
                'actions'      => $buttons === [] ? null : $buttons,
                'frasmActions' => $handlers === [] ? null : $handlers,
                'appBadge'     => $this->appBadge,
                'dataOnly'     => $this->dataOnly ? true : null,
            ],
            fn(mixed $value): bool => $value !== null && $value !== ''
        );

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > Crypto::MAX_PAYLOAD_LENGTH) {
            throw new PushException('Push payload is ' . strlen($json) . ' bytes; the limit is ' . Crypto::MAX_PAYLOAD_LENGTH . '.');
        }

        return $json;
    }

    /**
     * @brief Validates action button definitions.
     *
     * @param array<int, mixed> $actions Action definitions.
     * @return void
     * @throws PushException On invalid definitions.
     */
    private static function validateActions(array $actions): void
    {
        if (count($actions) > self::MAX_ACTIONS) {
            throw new PushException('A push message supports at most ' . self::MAX_ACTIONS . ' actions.');
        }

        foreach ($actions as $action) {
            if (!is_array($action) || !is_string($action['action'] ?? null) || !is_string($action['title'] ?? null)
                || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $action['action'])) {
                throw new PushException("Push action requires 'action' (identifier) and 'title'.");
            }

            foreach (['url', 'post'] as $key) {
                // Same-origin paths only: the service worker must never be pointed at foreign hosts
                if (isset($action[$key]) && (!is_string($action[$key]) || !preg_match('#^/(?!/)[^\s\\\\]*$#D', $action[$key]))) {
                    throw new PushException("Push action '{$key}' must be a same-origin path starting with '/'.");
                }
            }

            if (isset($action['url'], $action['post'])) {
                throw new PushException("Push action '{$action['action']}' may define either 'url' or 'post', not both.");
            }
        }
    }
}
