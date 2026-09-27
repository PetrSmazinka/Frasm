<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Http\Kernel;
use Core\Routing\RouteCache;

/**
 * @file RouteCacheCommand.php
 * @brief Builds the route cache.
 */
final class RouteCacheCommand extends Command
{
    /**
     * @brief RouteCacheCommand constructor.
     *
     * @param Kernel $kernel HTTP kernel (builds the cache).
     * @param RouteCache $routeCache Route cache.
     */
    public function __construct(private readonly Kernel $kernel, private readonly RouteCache $routeCache)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'route:cache';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Build the route cache (run after every deploy)';
    }

    /**
     * @brief Rebuilds the cache.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $this->routeCache->clear();
        $count = $this->kernel->rebuildRouteCache();

        if (!is_file($this->routeCache->path())) {
            $output->error("Route cache could not be written to {$this->routeCache->path()} (check permissions).");
            return 1;
        }

        $output->success("Route cache built ({$count} routes)");
        if (!$this->routeCache->isEnabled()) {
            $output->warning('routing.cache is disabled: the cache is not used until you enable it.');
        }

        return 0;
    }
}
