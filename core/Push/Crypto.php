<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Exceptions\PushException;
use OpenSSLAsymmetricKey;

/**
 * @file Crypto.php
 * @brief Web Push cryptography on top of ext-openssl (no external dependencies).
 */

/**
 * @class Crypto
 * @brief P-256 key handling, ECDSA signature conversion and RFC 8291 (aes128gcm) payload encryption.
 *
 * Keys are exchanged in the formats used by the Push API: base64url encoded uncompressed
 * public points (65 bytes, 0x04 || X || Y) and raw 32-byte private scalars.
 */
final class Crypto
{
    /**
     * @var string DER prefix of a SubjectPublicKeyInfo for an uncompressed prime256v1 point.
     */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * @var int Record size announced in the aes128gcm header.
     */
    public const RECORD_SIZE = 4096;

    /**
     * @var int Maximum plaintext payload: 4096-byte push message limit minus 86-byte header, 16-byte tag and 1 delimiter byte.
     */
    public const MAX_PAYLOAD_LENGTH = 3993;

    /**
     * @brief Prevents instantiation of the static helper.
     */
    private function __construct()
    {
    }

    /**
     * @brief Encodes binary data as unpadded base64url.
     *
     * @param string $data Binary data.
     * @return string
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @brief Decodes (padded or unpadded) base64url data.
     *
     * @param string $data Encoded data.
     * @return string Binary data.
     * @throws PushException On invalid input.
     */
    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new PushException('Invalid base64url data.');
        }

        return $decoded;
    }

    /**
     * @brief Generates a new P-256 key pair.
     *
     * @return array{public: string, private: string} Raw uncompressed public point (65 B) and private scalar (32 B).
     * @throws PushException If key generation fails.
     */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new PushException('Unable to generate a P-256 key pair: ' . (string)openssl_error_string());
        }

        return [
            'public'  => "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
            'private' => str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    /**
     * @brief Loads an OpenSSL public key from a raw uncompressed P-256 point.
     *
     * @param string $point 65-byte uncompressed point.
     * @return OpenSSLAsymmetricKey
     * @throws PushException If the point is malformed or not on the curve.
     */
    public static function publicKeyFromRaw(string $point): OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new PushException('Invalid P-256 public key: expected a 65-byte uncompressed point.');
        }

        $der = hex2bin(self::P256_SPKI_PREFIX) . $point;
        $key = openssl_pkey_get_public(self::pem($der, 'PUBLIC KEY'));
        if ($key === false) {
            throw new PushException('Invalid P-256 public key: ' . (string)openssl_error_string());
        }

        return $key;
    }

    /**
     * @brief Loads an OpenSSL private key from a raw scalar and its public point.
     *
     * @param string $private 32-byte private scalar.
     * @param string $public 65-byte uncompressed public point.
     * @return OpenSSLAsymmetricKey
     * @throws PushException If the key material is invalid.
     */
    public static function privateKeyFromRaw(string $private, string $public): OpenSSLAsymmetricKey
    {
        if (strlen($private) !== 32 || strlen($public) !== 65 || $public[0] !== "\x04") {
            throw new PushException('Invalid P-256 private key material.');
        }

        // SEC1 ECPrivateKey: version 1, private key, [0] curve OID, [1] public key
        $der = hex2bin('30770201010420') . $private
            . hex2bin('a00a06082a8648ce3d030107a144034200') . $public;

        $key = openssl_pkey_get_private(self::pem($der, 'EC PRIVATE KEY'));
        if ($key === false) {
            throw new PushException('Invalid P-256 private key: ' . (string)openssl_error_string());
        }

        return $key;
    }

    /**
     * @brief Converts a DER encoded ECDSA signature into the 64-byte R || S form used by JWS (ES256).
     *
     * @param string $der DER signature from openssl_sign().
     * @return string
     * @throws PushException On malformed DER input.
     */
    public static function derToRawSignature(string $der): string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new PushException('Invalid ECDSA signature encoding.');
        }
        $offset++; // Sequence length (always < 128 for P-256)

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new PushException('Invalid ECDSA signature encoding.');
            }
            $length = ord($der[$offset++] ?? "\0");
            $integer = ltrim(substr($der, $offset, $length), "\0");
            $offset += $length;

            if (strlen($integer) > 32) {
                throw new PushException('Invalid ECDSA signature integer length.');
            }
            $parts[] = str_pad($integer, 32, "\0", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /**
     * @brief Encrypts a push payload with the aes128gcm content coding (RFC 8188 / RFC 8291).
     *
     * @param string $payload Plaintext (max MAX_PAYLOAD_LENGTH bytes).
     * @param string $userPublicKey Subscriber's raw p256dh public point (65 B).
     * @param string $authSecret Subscriber's raw auth secret (16 B).
     * @param string|null $salt 16-byte salt (random when null; fixed values are for test vectors only).
     * @param array{public: string, private: string}|null $serverKeys Ephemeral key pair (generated when null; test vectors only).
     * @return string Request body: header (salt, record size, key id) followed by the ciphertext and tag.
     * @throws PushException On invalid keys or oversized payload.
     */
    public static function encrypt(
        string $payload,
        string $userPublicKey,
        string $authSecret,
        ?string $salt = null,
        ?array $serverKeys = null
    ): string {
        if (strlen($payload) > self::MAX_PAYLOAD_LENGTH) {
            throw new PushException('Push payload exceeds ' . self::MAX_PAYLOAD_LENGTH . ' bytes.');
        }
        if (strlen($authSecret) !== 16) {
            throw new PushException('Invalid push subscription auth secret: expected 16 bytes.');
        }

        $salt ??= random_bytes(16);
        $serverKeys ??= self::generateKeyPair();

        $sharedSecret = openssl_pkey_derive(
            self::publicKeyFromRaw($userPublicKey),
            self::privateKeyFromRaw($serverKeys['private'], $serverKeys['public']),
            32
        );
        if ($sharedSecret === false) {
            throw new PushException('ECDH key agreement failed: ' . (string)openssl_error_string());
        }

        // RFC 8291 §3.4: combine the ECDH secret with the subscriber's auth secret
        $keyInfo = "WebPush: info\0" . $userPublicKey . $serverKeys['public'];
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);

        // RFC 8188 §2.2: content encryption key and nonce
        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Single record terminated by the 0x02 padding delimiter
        $tag = '';
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new PushException('AES-128-GCM encryption failed: ' . (string)openssl_error_string());
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($serverKeys['public'])) . $serverKeys['public']
            . $ciphertext . $tag;
    }

    /**
     * @brief Wraps DER data in PEM armor.
     *
     * @param string $der DER bytes.
     * @param string $label PEM label.
     * @return string
     */
    private static function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}
