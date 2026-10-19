<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config\Config;
use Core\DB\DB;
use Core\Session\Session;
use InvalidArgumentException;
use Throwable;

/**
 * @file Passkeys.php
 * @brief Sign-in with passkeys (WebAuthn): a fingerprint, a face or the PIN of the device instead of the password.
 */

/**
 * @class Passkeys
 * @brief Registers the passkeys of the users (frasm_passkeys) and verifies the sign-ins with them.
 *
 * Relying party: `auth.passkeys.rp_id` (default the host of the request; set the registrable domain, e.g.
 * 'example.com', to share the keys by all its subdomains), name `auth.passkeys.rp_name` (default app.name). Passkeys
 * are discoverable (the device offers its keys, no user name typed) and need user verification. Algorithms ES256 and
 * RS256; attestation is not asked for. The challenge of a ceremony lives in the session for CHALLENGE_TTL seconds and
 * is used once (the last PENDING are kept, the device's answer picks its own). A sign count going back marks a cloned key: the sign-in is refused.
 *
 * Browser side: public/js/frasm-passkey.js (Frasm.passkey), endpoints: PasskeyController.
 */
class Passkeys
{
    /**
     * @var int Seconds a challenge is valid.
     */
    public const CHALLENGE_TTL = 300;

    /**
     * @var string Session key of the pending ceremony.
     */
    private const SESSION_KEY = '_frasm_passkey';

    /**
     * @var int Challenges kept in the session at once.
     */
    private const PENDING = 5;

    /**
     * @var int Most passkeys of one user.
     */
    public const MAX_PER_USER = 20;

    /**
     * @brief Tells whether passkeys are enabled (`auth.passkeys.enabled`).
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Config::get('auth.passkeys.enabled', false);
    }

    /**
     * @brief Options of navigator.credentials.create() for a signed-in user (the challenge is remembered).
     *
     * @param int $userId Local user ID.
     * @param string $host Host of the request.
     * @return array<string, mixed> PublicKeyCredentialCreationOptions with base64url binary fields.
     * @throws InvalidArgumentException When the user does not exist or has too many passkeys.
     */
    public function registrationOptions(int $userId, string $host): array
    {
        $db = DB::getInstance();
        $user = $db->selectOne('SELECT `id`, `name`, `username`, `email` FROM `frasm_users` WHERE `id` = ?', [$userId])
            ?? throw new InvalidArgumentException('unknown_user');
        $existing = $db->select('SELECT `credential_id`, `transports` FROM `frasm_passkeys` WHERE `user_id` = ?', [$userId]);
        if (count($existing) >= self::MAX_PER_USER) {
            throw new InvalidArgumentException('too_many');
        }
        $challenge = random_bytes(32);
        $handle = $this->userHandle($userId);
        $this->remember(['type' => 'webauthn.create', 'challenge' => self::encode($challenge), 'user' => $userId,
            'handle' => self::encode($handle), 'expires' => time() + self::CHALLENGE_TTL]);

        return [
            'challenge'              => self::encode($challenge),
            'rp'                     => ['id' => self::rpId($host), 'name' => self::rpName()],
            'user'                   => [
                'id'          => self::encode($handle),
                'name'        => (string)($user['email'] !== '' ? $user['email'] : $user['username']),
                'displayName' => (string)($user['name'] !== '' ? $user['name'] : $user['username']),
            ],
            'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials'     => array_map(fn(array $row): array => ['type' => 'public-key', 'id' => self::encode((string)$row['credential_id']),
                'transports' => array_values(array_filter(explode(',', (string)$row['transports'])))], $existing),
            'attestation'            => 'none',
            'timeout'                => self::CHALLENGE_TTL * 1000,
        ];
    }

    /**
     * @brief Stores a new passkey from the response of navigator.credentials.create().
     *
     * @param int $userId Signed-in user (the one the challenge was made for).
     * @param string $origin Origin of the page (https://host).
     * @param string $clientDataJson Decoded clientDataJSON.
     * @param string $attestationObject Decoded attestationObject.
     * @param string $name Name of the passkey given by the user (e.g. "Telefon").
     * @param list<string> $transports Transports reported by the browser.
     * @return int Passkey ID.
     * @throws InvalidArgumentException With a reason when the response is not valid.
     */
    public function register(int $userId, string $origin, string $clientDataJson, string $attestationObject, string $name, array $transports = []): int
    {
        $pending = $this->takeChallenge('webauthn.create', $clientDataJson);
        if ((int)($pending['user'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('challenge');
        }
        $rpId = $this->verifyClientData($clientDataJson, 'webauthn.create', (string)$pending['challenge'], $origin);
        try {
            $attestation = Cbor::decode($attestationObject);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('malformed');
        }
        if (!is_array($attestation) || !is_string($attestation['authData'] ?? null)) {
            throw new InvalidArgumentException('malformed');
        }
        $data = self::authenticatorData($attestation['authData'], $rpId, true);
        if ($data['credential'] === null) {
            throw new InvalidArgumentException('malformed');
        }
        [$credentialId, $publicKey, $algorithm] = $data['credential'];

        $db = DB::getInstance();
        if ($db->selectValue('SELECT 1 FROM `frasm_passkeys` WHERE `credential_id` = ?', [$credentialId]) !== null) {
            throw new InvalidArgumentException('exists');
        }
        $transports = array_values(array_intersect(['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'], $transports));
        $id = (int)$db->insert('frasm_passkeys', [
            'user_id'       => $userId,
            'credential_id' => $credentialId,
            'user_handle'   => self::decode((string)($pending['handle'] ?? '')) ?? $this->userHandle($userId),
            'public_key'    => $publicKey,
            'algorithm'     => $algorithm,
            'sign_count'    => $data['signCount'],
            'transports'    => implode(',', $transports),
            'name'          => mb_substr(trim($name) !== '' ? trim($name) : 'Přístupový klíč', 0, 100),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /**
     * @brief Options of navigator.credentials.get() for a sign-in (any passkey of this site; the challenge is
     *        remembered).
     *
     * @param string $host Host of the request.
     * @return array<string, mixed> PublicKeyCredentialRequestOptions with base64url binary fields.
     */
    public function authenticationOptions(string $host): array
    {
        $challenge = random_bytes(32);
        $this->remember(['type' => 'webauthn.get', 'challenge' => self::encode($challenge), 'expires' => time() + self::CHALLENGE_TTL]);

        return [
            'challenge'        => self::encode($challenge),
            'rpId'             => self::rpId($host),
            'allowCredentials' => [],
            'userVerification' => 'required',
            'timeout'          => self::CHALLENGE_TTL * 1000,
        ];
    }

    /**
     * @brief Verifies the response of navigator.credentials.get() and returns the user it signs in.
     *
     * @param string $origin Origin of the page.
     * @param string $credentialId Decoded credential ID.
     * @param string $clientDataJson Decoded clientDataJSON.
     * @param string $authenticatorData Decoded authenticatorData.
     * @param string $signature Decoded signature.
     * @param string|null $userHandle Decoded userHandle (checked when given).
     * @return int Local user ID.
     * @throws InvalidArgumentException With a reason when the sign-in is not valid.
     */
    public function authenticate(string $origin, string $credentialId, string $clientDataJson, string $authenticatorData, string $signature, ?string $userHandle): int
    {
        $pending = $this->takeChallenge('webauthn.get', $clientDataJson);
        $db = DB::getInstance();
        $passkey = $db->selectOne('SELECT * FROM `frasm_passkeys` WHERE `credential_id` = ?', [$credentialId])
            ?? throw new InvalidArgumentException('unknown');
        if ($userHandle !== null && $userHandle !== '' && !hash_equals((string)$passkey['user_handle'], $userHandle)) {
            throw new InvalidArgumentException('unknown');
        }
        $rpId = $this->verifyClientData($clientDataJson, 'webauthn.get', (string)$pending['challenge'], $origin);
        $data = self::authenticatorData($authenticatorData, $rpId, false);

        $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
        $key = openssl_pkey_get_public((string)$passkey['public_key']);
        if ($key === false || openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('signature');
        }
        // A counter that does not grow (when the device counts at all) means a copied key
        $stored = (int)$passkey['sign_count'];
        if ($stored > 0 && $data['signCount'] <= $stored) {
            throw new InvalidArgumentException('cloned');
        }
        $db->update('frasm_passkeys', ['sign_count' => $data['signCount'], 'last_used_at' => date('Y-m-d H:i:s')], '`id` = ?', [(int)$passkey['id']]);

        return (int)$passkey['user_id'];
    }

    /**
     * @brief Passkeys of a user, newest first.
     *
     * @param int $userId Local user ID.
     * @return list<array{id: int, name: string, created_at: string, last_used_at: string|null}>
     */
    public function forUser(int $userId): array
    {
        return array_map(fn(array $row): array => [
            'id'           => (int)$row['id'],
            'name'         => (string)$row['name'],
            'created_at'   => (string)$row['created_at'],
            'last_used_at' => $row['last_used_at'] === null ? null : (string)$row['last_used_at'],
        ], DB::getInstance()->select('SELECT `id`, `name`, `created_at`, `last_used_at` FROM `frasm_passkeys` WHERE `user_id` = ? ORDER BY `id` DESC', [$userId]));
    }

    /**
     * @brief Removes a passkey of a user (the device keeps it, but it no longer signs in).
     *
     * @param int $userId Owner.
     * @param int $id Passkey ID.
     * @return bool False when it is not theirs.
     */
    public function remove(int $userId, int $id): bool
    {
        return DB::getInstance()->delete('frasm_passkeys', '`id` = ? AND `user_id` = ?', [$id, $userId]) > 0;
    }

    /**
     * @brief Relying party ID: `auth.passkeys.rp_id` or the host.
     *
     * @param string $host Host of the request.
     * @return string
     */
    public static function rpId(string $host): string
    {
        $configured = strtolower(trim((string)Config::get('auth.passkeys.rp_id', '')));
        $host = strtolower((string)preg_replace('/:\d+$/', '', $host));

        return $configured !== '' && ($host === $configured || str_ends_with($host, '.' . $configured)) ? $configured : $host;
    }

    /**
     * @brief Name of the relying party shown by the device.
     *
     * @return string
     */
    private static function rpName(): string
    {
        return (string)Config::get('auth.passkeys.rp_name', Config::get('app.name', 'Frasm'));
    }

    /**
     * @brief Checks clientDataJSON: type, challenge, origin (HTTPS on the relying party or its subdomain).
     *
     * @param string $json clientDataJSON.
     * @param string $type Expected type.
     * @param string $challenge Expected challenge (base64url).
     * @param string $origin Origin of the request.
     * @return string Relying party ID for the origin.
     * @throws InvalidArgumentException
     */
    private function verifyClientData(string $json, string $type, string $challenge, string $origin): string
    {
        $client = json_decode($json, true);
        if (!is_array($client) || ($client['type'] ?? null) !== $type || !is_string($client['challenge'] ?? null)
            || !hash_equals($challenge, rtrim((string)$client['challenge'], '='))) {
            throw new InvalidArgumentException('challenge');
        }
        if (($client['crossOrigin'] ?? false) === true || !is_string($client['origin'] ?? null) || $client['origin'] !== $origin) {
            throw new InvalidArgumentException('origin');
        }
        $host = (string)parse_url($origin, PHP_URL_HOST);
        if (!str_starts_with($origin, 'https://') || $host === '') {
            throw new InvalidArgumentException('origin');
        }

        return self::rpId($host);
    }

    /**
     * @brief Parses authenticator data and checks the relying party and the flags (user present and verified).
     *
     * @param string $data Authenticator data.
     * @param string $rpId Relying party ID.
     * @param bool $attested A credential is expected (registration).
     * @return array{signCount: int, credential: array{0: string, 1: string, 2: int}|null} Credential: ID, PEM, algorithm.
     * @throws InvalidArgumentException
     */
    private static function authenticatorData(string $data, string $rpId, bool $attested): array
    {
        if (strlen($data) < 37 || !hash_equals(hash('sha256', $rpId, true), substr($data, 0, 32))) {
            throw new InvalidArgumentException('rp');
        }
        $flags = ord($data[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw new InvalidArgumentException('verification');
        }
        $signCount = (int)unpack('N', substr($data, 33, 4))[1];
        if (!$attested) {
            return ['signCount' => $signCount, 'credential' => null];
        }
        if (($flags & 0x40) === 0 || strlen($data) < 55) {
            throw new InvalidArgumentException('malformed');
        }
        $length = (int)unpack('n', substr($data, 53, 2))[1];
        $credentialId = substr($data, 55, $length);
        if ($length < 16 || $length > 1023 || strlen($credentialId) !== $length) {
            throw new InvalidArgumentException('malformed');
        }
        $offset = 55 + $length;
        try {
            $cose = Cbor::decode($data, $offset);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('malformed');
        }
        [$pem, $algorithm] = self::coseToPem(is_array($cose) ? $cose : []);

        return ['signCount' => $signCount, 'credential' => [$credentialId, $pem, $algorithm]];
    }

    /**
     * @brief Turns a COSE public key into PEM (EC2 P-256 / ES256, RSA / RS256).
     *
     * @param array<int, mixed> $cose COSE key.
     * @return array{0: string, 1: int} PEM and COSE algorithm.
     * @throws InvalidArgumentException For other keys.
     */
    private static function coseToPem(array $cose): array
    {
        $algorithm = (int)($cose[3] ?? 0);
        if (($cose[1] ?? null) === 2 && $algorithm === -7 && ($cose[-1] ?? null) === 1
            && is_string($cose[-2] ?? null) && strlen($cose[-2]) === 32 && is_string($cose[-3] ?? null) && strlen($cose[-3]) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $cose[-2] . $cose[-3];
        } elseif (($cose[1] ?? null) === 3 && $algorithm === -257 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
            $key = self::der(0x30, self::derInteger($cose[-1]) . self::derInteger($cose[-2]));
            $der = self::der(0x30, self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00") . self::der(0x03, "\x00" . $key));
        } else {
            throw new InvalidArgumentException('algorithm');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        try {
            if (openssl_pkey_get_public($pem) === false) {
                throw new InvalidArgumentException('algorithm');
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('algorithm');
        }

        return [$pem, $algorithm];
    }

    /**
     * @brief DER element.
     *
     * @param int $tag Tag.
     * @param string $content Content.
     * @return string
     */
    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 128) {
            return chr($tag) . chr($length) . $content;
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }

    /**
     * @brief DER INTEGER of an unsigned big-endian number.
     *
     * @param string $bytes Number.
     * @return string
     */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    /**
     * @brief Remembers the challenge of a ceremony in the session; the last few are kept (a second tab or a double
     *        click starts another ceremony without breaking the first).
     *
     * @param array<string, mixed> $pending Challenge with its type, expiry and data.
     * @return void
     */
    private function remember(array $pending): void
    {
        $list = array_filter((array)Session::get(self::SESSION_KEY, []), fn(mixed $item): bool => is_array($item) && (int)($item['expires'] ?? 0) >= time());
        $list[] = $pending;
        Session::set(self::SESSION_KEY, array_values(array_slice($list, -self::PENDING)));
    }

    /**
     * @brief Takes the pending challenge the device signed (once).
     *
     * @param string $type webauthn.create or webauthn.get.
     * @param string $clientDataJson clientDataJSON from the device (its challenge chooses the ceremony).
     * @return array<string, mixed>
     * @throws InvalidArgumentException 'challenge' when there is none for it or it expired.
     */
    private function takeChallenge(string $type, string $clientDataJson): array
    {
        $client = json_decode($clientDataJson, true);
        $signed = is_array($client) && is_string($client['challenge'] ?? null) ? rtrim($client['challenge'], '=') : '';
        $list = array_values(array_filter((array)Session::get(self::SESSION_KEY, []), 'is_array'));
        foreach ($list as $index => $pending) {
            if (($pending['type'] ?? null) === $type && hash_equals((string)($pending['challenge'] ?? ''), $signed)) {
                unset($list[$index]);
                Session::set(self::SESSION_KEY, array_values($list));
                if ((int)($pending['expires'] ?? 0) < time()) {
                    throw new InvalidArgumentException('challenge');
                }

                return $pending;
            }
        }

        throw new InvalidArgumentException('challenge');
    }

    /**
     * @brief The user handle of a user (random, the same for all their passkeys).
     *
     * @param int $userId Local user ID.
     * @return string 32 bytes.
     */
    private function userHandle(int $userId): string
    {
        $handle = DB::getInstance()->selectValue('SELECT `user_handle` FROM `frasm_passkeys` WHERE `user_id` = ? LIMIT 1', [$userId]);

        return is_string($handle) && $handle !== '' ? $handle : random_bytes(32);
    }

    /**
     * @brief base64url without padding.
     *
     * @param string $bytes Bytes.
     * @return string
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @brief Decodes base64url (with or without padding).
     *
     * @param string $value Text.
     * @return string|null Null when malformed.
     */
    public static function decode(string $value): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $value)) {
            return null;
        }
        $bytes = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
