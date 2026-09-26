<?php

declare(strict_types=1);

/**
 * @file routes.php
 * @brief Route table console tool: build or clear the route cache and list registered routes.
 *
 * Usage: php bin/routes.php <cache|clear|list>
 */

use Core\Config\Config;
use Core\Container\Container;
use Core\Http\Kernel;
use Core\Routing\RouteCache;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$container = Container::getInstance();
$action = $argv[1] ?? 'help';

try {
    /** @var RouteCache $cache */
    $cache = $container->get(RouteCache::class);

    switch ($action) {
        case 'cache':
            $cache->clear();
            $count = $container->get(Kernel::class)->rebuildRouteCache();
            echo "✔ Route cache written to {$cache->path()} ({$count} routes).\n";
            if (!$cache->isEnabled()) {
                echo "  Note: routing.cache is disabled, the cache file will not be used until it is enabled.\n";
            }
            break;

        case 'clear':
            if (!$cache->clear()) {
                throw new RuntimeException("Cannot delete {$cache->path()} (check file permissions).");
            }
            echo "✔ Route cache cleared.\n";
            break;

        case 'list':
            $kernel = $container->get(Kernel::class);
            $kernel->boot();

            $rows = [];
            foreach ($kernel->router()->getRoutes() as $route) {
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

            $headers = ['METHOD', 'PATH', 'HANDLER', 'AUTH', 'MIDDLEWARE'];
            $widths = array_map('strlen', $headers);
            foreach ($rows as $row) {
                foreach ($row as $i => $cell) {
                    $widths[$i] = max($widths[$i], strlen($cell));
                }
            }

            foreach (array_merge([$headers], $rows) as $row) {
                $line = '';
                foreach ($row as $i => $cell) {
                    $line .= str_pad($cell, $widths[$i] + 2);
                }
                echo rtrim($line) . "\n";
            }
            echo "\nRoute cache: " . ($cache->isEnabled() ? 'enabled' : 'disabled')
                . (is_file($cache->path()) ? ", file present" : ", no file")
                . ", validation " . ($cache->shouldValidate() ? 'on' : 'off') . "\n";
            break;

        default:
            echo "Frasm Route Tool\n";
            echo "----------------\n";
            echo "Usage: php bin/routes.php <command>\n\n";
            echo "Commands:\n";
            echo "  cache    Build the route cache (run after every deploy when validation is off)\n";
            echo "  clear    Delete the route cache\n";
            echo "  list     Print all registered routes\n";
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error: " . $e->getMessage() . "\n");
    exit(1);
}
