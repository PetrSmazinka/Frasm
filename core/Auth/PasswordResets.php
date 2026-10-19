<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config\Config;
use Core\DB\DB;
use InvalidArgumentException;

/**
 * @file PasswordResets.php
 * @brief One-time links to set a new password (a forgotten one).
 */

/**
 * @class PasswordResets
 * @brief Issues a token for a link valid `auth.password_reset_ttl` seconds (default 2 hours), checks it and sets the new
 *        password with it.
 *
 * Only the SHA-256 of a token is stored; a new link replaces the older unused ones of the user; using one ends the
 * remember-me logins of the account (other sessions end by the changed password). The application sends the link in
 * its own e-mail (its sender, its address); an SSO server issues links for its clients (`client`), which send them to
 * their users and complete them through the API.
 */
class PasswordResets
{
    /**
     * @var int Shortest password.
     */
    public const MIN_PASSWORD = 8;

    /**
     * @brief Issues a link for a user.
     *
     * @param int $userId User ID.
     * @param string|null $client SSO client that asked (null = this application).
     * @return string Token for the link (hex).
     */
    public function issue(int $userId, ?string $client = null): string
    {
        $token = bin2hex(random_bytes(32));
        $db = DB::getInstance();
        $db->query('DELETE FROM `frasm_password_resets` WHERE `user_id` = ? AND `used_at` IS NULL', [$userId]);
        $db->insert('frasm_password_resets', [
            'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'client' => $client,
            'expires_at' => date('Y-m-d H:i:s', time() + self::ttl()),
        ]);

        return $token;
    }

    /**
     * @brief The user of a valid (unused, unexpired) token.
     *
     * @param string $token Token of the link.
     * @param string|null $client SSO client completing it (null = this application).
     * @return int|null User ID, null when the link is invalid.
     */
    public function userOf(string $token, ?string $client = null): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $token)) {
            return null;
        }
        $row = DB::getInstance()->selectOne(
            'SELECT `user_id`, `client` FROM `frasm_password_resets` WHERE `token_hash` = ? AND `used_at` IS NULL AND `expires_at` > NOW()',
            [hash('sha256', $token)]
        );

        return $row !== null && $row['client'] === $client ? (int)$row['user_id'] : null;
    }

    /**
     * @brief Sets a new password with a link (once).
     *
     * @param string $token Token of the link.
     * @param string $password New password.
     * @param string|null $client SSO client completing it (null = this application).
     * @return int|null User ID, null when the link is invalid.
     * @throws InvalidArgumentException When the password is too short or long.
     */
    public function complete(string $token, string $password, ?string $client = null): ?int
    {
        if (mb_strlen($password) < self::MIN_PASSWORD || strlen($password) > 1024) {
            throw new InvalidArgumentException('weak_password');
        }
        $userId = $this->userOf($token, $client);
        if ($userId === null) {
            return null;
        }
        $db = DB::getInstance();
        $db->transactional(function (DB $db) use ($userId, $password, $token): void {
            $db->query('UPDATE `frasm_password_resets` SET `used_at` = NOW() WHERE `token_hash` = ?', [hash('sha256', $token)]);
            $db->query('UPDATE `frasm_users` SET `password_hash` = ? WHERE `id` = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            $db->query('DELETE FROM `frasm_remember_tokens` WHERE `user_id` = ?', [$userId]);
        });

        return $userId;
    }

    /**
     * @brief Validity of a link in seconds (`auth.password_reset_ttl`).
     *
     * @return int
     */
    public static function ttl(): int
    {
        return max(300, (int)Config::get('auth.password_reset_ttl', 7200));
    }
}
