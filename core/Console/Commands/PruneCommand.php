<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Auth\Auth;
use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\RateLimit\RateLimiter;

/**
 * @file PruneCommand.php
 * @brief Deletes expired remember-me tokens and rate limit counters.
 */
final class PruneCommand extends Command
{
    /**
     * @brief PruneCommand constructor.
     *
     * @param RateLimiter $rateLimiter Rate limiter.
     */
    public function __construct(private readonly RateLimiter $rateLimiter)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'prune';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Delete expired remember-me tokens and rate limit counters (cron)';
    }

    /**
     * @brief Prunes expired records.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if ((bool)Config::get('auth.enabled', true)) {
            $output->success('Deleted ' . Auth::pruneExpiredTokens() . ' expired remember-me token(s)');
        }

        $output->success('Deleted ' . $this->rateLimiter->prune() . ' expired rate limit counter(s)');
        return 0;
    }
}
