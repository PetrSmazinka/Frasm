<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Exceptions\PushException;

/**
 * @file Subscription.php
 * @brief Immutable browser push subscription (PushSubscription.toJSON()).
 */

/**
 * @class Subscription
 * @brief Endpoint URL plus the subscriber's encryption keys.
 */
final class Subscription
{
    /**
     * @brief Subscription constructor.
     *
     * @param string $endpoint Push service endpoint URL.
     * @param string $p256dh Base64url subscriber public key.
     * @param string $auth Base64url subscriber auth secret.
     * @param int|null $id Database id (null when not persisted).
     * @param int|string|null $userId Owning user id.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
        public readonly ?int $id = null,
        public readonly int|string|null $userId = null
    ) {
    }

    /**
     * @brief Creates a validated subscription from the browser's PushSubscription JSON.
     *
     * Only HTTPS endpoints on allowed push service hosts are accepted (SSRF protection: the server
     * later sends requests to this URL). Keys must decode to a P-256 point and a 16-byte secret.
     *
     * @param mixed $data Decoded JSON: {endpoint, keys: {p256dh, auth}}.
     * @param list<string> $allowedHosts Host names; '*.example.com' matches subdomains.
     * @return self
     * @throws PushException On any invalid field.
     */
    public static function fromBrowser(mixed $data, array $allowedHosts): self
    {
        if (!is_array($data)) {
            throw new PushException('Push subscription payload must be a JSON object.', 400);
        }

        $endpoint = $data['endpoint'] ?? null;
        $p256dh = $data['keys']['p256dh'] ?? null;
        $auth = $data['keys']['auth'] ?? null;

        if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
            throw new PushException('Push subscription requires endpoint, keys.p256dh and keys.auth.', 400);
        }

        self::assertEndpointAllowed($endpoint, $allowedHosts);

        if (strlen($p256dh) > 128 || strlen($auth) > 64
            || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $p256dh) || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $auth)) {
            throw new PushException('Push subscription keys must be base64url encoded.', 400);
        }

        try {
            Crypto::publicKeyFromRaw(Crypto::base64UrlDecode($p256dh));
            $authLength = strlen(Crypto::base64UrlDecode($auth));
        } catch (PushException $e) {
            throw new PushException('Invalid push subscription key: ' . $e->getMessage(), 400, $e);
        }

        if ($authLength !== 16) {
            throw new PushException('Push subscription auth secret must be 16 bytes.', 400);
        }

        return new self($endpoint, rtrim($p256dh, '='), rtrim($auth, '='));
    }

    /**
     * @brief Verifies that an endpoint is an HTTPS URL on an allowed push service host.
     *
     * @param string $endpoint Endpoint URL.
     * @param list<string> $allowedHosts Allowed host patterns.
     * @return void
     * @throws PushException When the endpoint is not allowed.
     */
    public static function assertEndpointAllowed(string $endpoint, array $allowedHosts): void
    {
        $parts = parse_url($endpoint);
        $host = strtolower((string)($parts['host'] ?? ''));

        if (strlen($endpoint) > 2048 || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new PushException('Push endpoint must be an HTTPS URL.', 400);
        }

        foreach ($allowedHosts as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === '*' || $host === $pattern
                || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return;
            }
        }

        throw new PushException("Push endpoint host '{$host}' is not an allowed push service (push.allowed_hosts).", 400);
    }

    /**
     * @brief Returns the SHA-256 hash of the endpoint used as unique key.
     *
     * @return string
     */
    public function endpointHash(): string
    {
        return hash('sha256', $this->endpoint);
    }
}
