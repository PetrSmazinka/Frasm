<?php

declare(strict_types=1);

/**
 * @file prune-tokens.php
 * @brief Maintenance CLI script to clean up expired remember-me tokens and rate limit counters.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

try {
    if ((bool)\Core\Config\Config::get('auth.enabled', true)) {
        $purged = \Core\Auth\Auth::pruneExpiredTokens();
        echo "[" . date('Y-m-d H:i:s') . "] Successfully purged {$purged} expired remember token(s).\n";
    }

    $purgedLimits = \Core\Container\Container::getInstance()->get(\Core\RateLimit\RateLimiter::class)->prune();
    echo "[" . date('Y-m-d H:i:s') . "] Successfully purged {$purgedLimits} expired rate limit counter(s).\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] Error pruning tokens: " . $e->getMessage() . "\n");
    exit(1);
}