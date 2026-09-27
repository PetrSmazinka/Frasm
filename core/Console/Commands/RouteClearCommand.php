<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Routing\RouteCache;

/**
 * @file RouteClearCommand.php
 * @brief Deletes the route cache.
 */
final class RouteClearCommand extends Command
{
    /**
     * @brief RouteClearCommand constructor.
     *
     * @param RouteCache $routeCache Route cache.
     */
    public function __construct(private readonly RouteCache $routeCache)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'route:clear';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Delete the route cache';
    }

    /**
     * @brief Deletes the cache file.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (!$this->routeCache->clear()) {
            $output->error("Cannot delete {$this->routeCache->path()} (check permissions).");
            return 1;
        }

        $output->success('Route cache cleared');
        return 0;
    }
}
