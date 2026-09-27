<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Http\Kernel;
use Core\Routing\RouteCache;

/**
 * @file RouteListCommand.php
 * @brief Prints the route table.
 */
final class RouteListCommand extends Command
{
    /**
     * @brief RouteListCommand constructor.
     *
     * @param Kernel $kernel HTTP kernel (registers routes).
     * @param RouteCache $routeCache Route cache (status reporting).
     */
    public function __construct(private readonly Kernel $kernel, private readonly RouteCache $routeCache)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'route:list';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'List all registered routes';
    }

    /**
     * @brief Prints the routes.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $this->kernel->boot();

        $rows = [];
        foreach ($this->kernel->router()->getRoutes() as $route) {
            $handler = $route->getHandler();
            $roles = $route->getMetadata('auth_roles');
            $rows[] = [
                $route->getMethod(),
                $route->getPath(),
                is_array($handler) ? $handler[0] . '::' . $handler[1] : 'Closure',
                $roles === null ? '' : ($roles === [] ? 'auth' : 'auth:' . implode(',', $roles)),
                implode(' ', (array)$route->getMetadata('middleware', [])),
            ];
        }

        $output->table(['Method', 'Path', 'Handler', 'Auth', 'Middleware'], $rows);
        $output->line();
        $output->comment('Route cache: ' . ($this->routeCache->isEnabled() ? 'enabled' : 'disabled')
            . (is_file($this->routeCache->path()) ? ', built' : ', not built')
            . ', validation ' . ($this->routeCache->shouldValidate() ? 'on' : 'off'));

        return 0;
    }
}
