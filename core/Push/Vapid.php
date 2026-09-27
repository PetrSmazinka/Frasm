<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Exceptions\PushException;
use OpenSSLAsymmetricKey;

/**
 * @file Vapid.php
 * @brief VAPID (RFC 8292) application server identification for Web Push.
 */

/**
 * @class Vapid
 * @brief Signs ES256 JWTs identifying the application server to push services.
 *
 * Tokens are cached per push service origin for the lifetime of the object, so a batch
 * sent to many subscribers of the same service signs only once.
 */
class Vapid
{
    /**
     * @var int JWT lifetime in seconds (RFC 8292 allows at most 24 hours).
     */
    protected const TOKEN_TTL = 43200;

    /**
     * @var string Raw public point (65 B).
     */
    protected string $publicKey;

    /**
     * @var OpenSSLAsymmetricKey Signing key.
     */
    protected OpenSSLAsymmetricKey $privateKey;

    /**
     * @var array<string, array{header: string, expires: int}> Cached Authorization headers by audience.
     */
    protected array $cache = [];

    /**
     * @brief Vapid constructor.
     *
     * @param string $subject Contact URI of the operator ('mailto:admin@example.com' or 'https://...').
     * @param string $publicKey Base64url encoded uncompressed public point.
     * @param string $privateKey Base64url encoded 32-byte private scalar.
     * @throws PushException On invalid subject or key material.
     */
    public function __construct(protected string $subject, string $publicKey, string $privateKey)
    {
        if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://')) {
            throw new PushException("VAPID subject must be a 'mailto:' or 'https://' URI (push.vapid.subject).");
        }
        if ($publicKey === '' || $privateKey === '') {
            throw new PushException('VAPID keys are not configured (push.vapid.public_key / private_key). Run `php bin/frasm push:vapid`.');
        }

        $this->publicKey = Crypto::base64UrlDecode($publicKey);
        $this->privateKey = Crypto::privateKeyFromRaw(Crypto::base64UrlDecode($privateKey), $this->publicKey);
    }

    /**
     * @brief Generates a new VAPID key pair.
     *
     * @return array{public_key: string, private_key: string} Base64url encoded keys.
     * @throws PushException If key generation fails.
     */
    public static function generateKeys(): array
    {
        $pair = Crypto::generateKeyPair();

        return [
            'public_key'  => Crypto::base64UrlEncode($pair['public']),
            'private_key' => Crypto::base64UrlEncode($pair['private']),
        ];
    }

    /**
     * @brief Returns the base64url public key (the browser's applicationServerKey).
     *
     * @return string
     */
    public function publicKey(): string
    {
        return Crypto::base64UrlEncode($this->publicKey);
    }

    /**
     * @brief Builds the Authorization header value for a push endpoint.
     *
     * @param string $endpoint Push subscription endpoint URL.
     * @return string `vapid t=<jwt>, k=<public key>`
     * @throws PushException If signing fails or the endpoint is not a URL.
     */
    public function authorizationHeader(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new PushException('Invalid push endpoint URL.');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $now = time();

        // Reuse the token while it has more than an hour of validity left
        if (isset($this->cache[$audience]) && $this->cache[$audience]['expires'] - 3600 > $now) {
            return $this->cache[$audience]['header'];
        }

        $expires = $now + self::TOKEN_TTL;
        $header = Crypto::base64UrlEncode('{"typ":"JWT","alg":"ES256"}');
        $claims = Crypto::base64UrlEncode(json_encode(
            ['aud' => $audience, 'exp' => $expires, 'sub' => $this->subject],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        $signature = '';
        if (!openssl_sign("{$header}.{$claims}", $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new PushException('VAPID signing failed: ' . (string)openssl_error_string());
        }

        $jwt = "{$header}.{$claims}." . Crypto::base64UrlEncode(Crypto::derToRawSignature($signature));
        $value = "vapid t={$jwt}, k=" . $this->publicKey();

        $this->cache[$audience] = ['header' => $value, 'expires' => $expires];

        return $value;
    }
}
