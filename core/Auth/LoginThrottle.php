<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config\Config;
use Core\RateLimit\RateLimiter;

/**
 * @file LoginThrottle.php
 * @brief Limits failed sign-ins per account and per address.
 */

/**
 * @class LoginThrottle
 * @brief Counts failed attempts to sign in (`auth.login_attempts`: max per account from one address, per_ip, window in
 *        seconds) and blocks further ones until the window passes; a successful sign-in clears the count of the account.
 *
 * The account is counted per address (max) – somebody else's failures do not lock the owner out – and over all
 * addresses with a higher limit (ACCOUNT_FACTOR × max) against guessing from many addresses.
 *
 * @code
 * if (($wait = $throttle->blocked($identifier, $request->ip())) !== null) { ... "try again in $wait s" ... }
 * if (!$valid) { $throttle->failed($identifier, $request->ip()); ... }
 * $throttle->succeeded($identifier, 'login', $request->ip());
 * @endcode
 *
 * The account is the identifier as typed (user name or e-mail, case-insensitive), so it holds also for accounts that do
 * not exist (nothing tells them apart). The same limiter guards other guessable forms (password reset requests).
 */
class LoginThrottle
{
    /**
     * @var int Failures of an account over all addresses allowed, as a multiple of the limit from one address.
     */
    private const ACCOUNT_FACTOR = 10;

    /**
     * @brief LoginThrottle constructor.
     *
     * @param RateLimiter $limiter Counters.
     */
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    /**
     * @brief Whether attempts are blocked now.
     *
     * @param string $identifier User name or e-mail as typed.
     * @param string $ip Address of the client.
     * @param string $action Kind of attempt ('login', 'reset', …): separate counters.
     * @return int|null Seconds until another attempt is allowed, null when it is allowed now.
     */
    public function blocked(string $identifier, string $ip, string $action = 'login'): ?int
    {
        [$max, $perIp] = self::limits();
        foreach ([[self::accountKey($identifier, $action) . ":ip:{$ip}", $max], [self::accountKey($identifier, $action), $max * self::ACCOUNT_FACTOR],
            ["{$action}:ip:{$ip}", $perIp]] as [$key, $limit]) {
            if ($this->limiter->tooManyAttempts($key, $limit)) {
                return max(1, $this->limiter->availableIn($key));
            }
        }

        return null;
    }

    /**
     * @brief Counts a failed attempt.
     *
     * @param string $identifier User name or e-mail as typed.
     * @param string $ip Address of the client.
     * @param string $action Kind of attempt.
     * @return void
     */
    public function failed(string $identifier, string $ip, string $action = 'login'): void
    {
        $window = self::limits()[2];
        $this->limiter->hit(self::accountKey($identifier, $action) . ":ip:{$ip}", $window);
        $this->limiter->hit(self::accountKey($identifier, $action), $window);
        $this->limiter->hit("{$action}:ip:{$ip}", $window);
    }

    /**
     * @brief Clears the count of an account after a successful sign-in.
     *
     * @param string $identifier User name or e-mail as typed.
     * @param string $action Kind of attempt.
     * @param string|null $ip Address of the client (its count of the account is cleared too).
     * @return void
     */
    public function succeeded(string $identifier, string $action = 'login', ?string $ip = null): void
    {
        $this->limiter->clear(self::accountKey($identifier, $action));
        if ($ip !== null) {
            $this->limiter->clear(self::accountKey($identifier, $action) . ":ip:{$ip}");
        }
    }

    /**
     * @brief Minutes to tell the user ("zkuste to za 5 minut").
     *
     * @param int $seconds Seconds of the block.
     * @return int
     */
    public static function minutes(int $seconds): int
    {
        return max(1, (int)ceil($seconds / 60));
    }

    /**
     * @brief Limits from `auth.login_attempts`.
     *
     * @return array{0: int, 1: int, 2: int} Per account, per address, window in seconds.
     */
    private static function limits(): array
    {
        $config = (array)Config::get('auth.login_attempts', []);

        return [max(1, (int)($config['max'] ?? 5)), max(1, (int)($config['per_ip'] ?? 20)), max(60, (int)($config['window'] ?? 900))];
    }

    /**
     * @brief Counter key of an account.
     *
     * @param string $identifier Identifier as typed.
     * @param string $action Kind of attempt.
     * @return string
     */
    private static function accountKey(string $identifier, string $action): string
    {
        return "{$action}:id:" . sha1(mb_strtolower(trim($identifier)));
    }
}
