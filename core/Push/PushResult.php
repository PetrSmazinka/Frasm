<?php

declare(strict_types=1);

namespace Core\Push;

/**
 * @file PushResult.php
 * @brief Delivery outcome for a single subscription.
 */

/**
 * @class PushResult
 * @brief HTTP outcome reported by the push service.
 */
final class PushResult
{
    /**
     * @brief PushResult constructor.
     *
     * @param Subscription $subscription Target subscription.
     * @param int $status HTTP status (0 on transport error).
     * @param string $error Transport error or response body excerpt.
     */
    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $status,
        public readonly string $error = ''
    ) {
    }

    /**
     * @brief Checks whether the push service accepted the message.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @brief Checks whether the subscription no longer exists (404/410) and should be deleted.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->status === 404 || $this->status === 410;
    }
}
