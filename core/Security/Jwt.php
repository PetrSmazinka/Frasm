<?php

declare(strict_types=1);

namespace Core\Security;

/**
 * @file Jwt.php
 * @brief JSON Web Tokens signed with HMAC-SHA256 (HS256) for talking to other services.
 */

/**
 * @class Jwt
 * @brief Encodes and verifies compact JWTs (RFC 7519) with the HS256 algorithm and a shared secret.
 *
 * Meant for services that authenticate requests with a JWT and a secret shared with the application
 * (document servers, webhooks, internal APIs). Only HS256 is accepted when decoding: the algorithm in the
 * header is never trusted, so a token with "alg": "none" or any other algorithm is rejected. The claims
 * `exp` and `nbf` are checked when present (with a leeway for clock differences); other claims are left
 * to the caller. For tokens the application issues to itself, Signer::token() is shorter.
 *
 * @code
 * $token = Jwt::encode(['document' => $id, 'exp' => time() + 300], $secret);
 * $claims = Jwt::decode($token, $secret);   // array, or null when forged, malformed or expired
 * @endcode
 */
final class Jwt
{
    /**
     * @var int Shortest secret accepted (bytes); RFC 7518 requires at least the size of the hash.
     */
    private const MIN_SECRET = 32;

    /**
     * @brief Prevents instantiation of the static helper.
     */
    private function __construct()
    {
    }

    /**
     * @brief Creates a signed token.
     *
     * @param array<string, mixed> $claims Payload (e.g. with 'exp' as a Unix time).
     * @param string $secret Shared secret (at least 32 bytes).
     * @return string Compact token header.payload.signature.
     * @throws \InvalidArgumentException When the secret is too short.
     * @throws \JsonException When the claims cannot be encoded.
     */
    public static function encode(array $claims, string $secret): string
    {
        self::assertSecret($secret);
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode((object)$claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $header . '.' . $payload . '.' . self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    }

    /**
     * @brief Verifies a token and returns its claims.
     *
     * @param string $token Compact token.
     * @param string $secret Shared secret.
     * @param int $leeway Seconds tolerated for `exp` and `nbf`.
     * @return array<string, mixed>|null Claims, or null for a malformed, forged, expired or not yet valid token.
     * @throws \InvalidArgumentException When the secret is too short.
     */
    public static function decode(string $token, string $secret, int $leeway = 0): ?array
    {
        self::assertSecret($secret);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $payload, $signature] = $parts;

        $expected = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $head = self::json($header);
        if ($head === null || ($head['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $claims = self::json($payload);
        if ($claims === null) {
            return null;
        }

        $now = time();
        if (array_key_exists('exp', $claims) && (!is_int($claims['exp']) || $claims['exp'] + $leeway < $now)) {
            return null;
        }
        if (array_key_exists('nbf', $claims) && (!is_int($claims['nbf']) || $claims['nbf'] - $leeway > $now)) {
            return null;
        }

        return $claims;
    }

    /**
     * @brief Returns the token of an "Authorization: Bearer <token>" header value.
     *
     * @param string|null $header Header value.
     * @return string|null Token, or null when the header is missing or of another scheme.
     */
    public static function fromBearer(?string $header): ?string
    {
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/Di', trim($header), $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @brief Decodes a base64url JSON object.
     *
     * @param string $part Encoded part.
     * @return array<string, mixed>|null
     */
    private static function json(string $part): ?array
    {
        $raw = base64_decode(strtr($part, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        try {
            $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) && !array_is_list($value) ? $value : (is_array($value) && $value === [] ? [] : null);
    }

    /**
     * @brief Encodes bytes as base64url without padding.
     *
     * @param string $bytes Raw bytes.
     * @return string
     */
    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @brief Rejects secrets too short for HS256.
     *
     * @param string $secret Secret.
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function assertSecret(string $secret): void
    {
        if (strlen($secret) < self::MIN_SECRET) {
            throw new \InvalidArgumentException('The JWT secret must have at least ' . self::MIN_SECRET . ' bytes.');
        }
    }
}
