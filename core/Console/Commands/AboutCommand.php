<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;
use Core\Frasm;
use Core\Routing\RouteCache;

/**
 * @file AboutCommand.php
 * @brief Prints an overview of the installation.
 */
final class AboutCommand extends Command
{
    /**
     * @brief AboutCommand constructor.
     *
     * @param RouteCache $routeCache Route cache (status reporting).
     */
    public function __construct(private readonly RouteCache $routeCache)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'about';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Show framework version, environment and enabled modules';
    }

    /**
     * @brief Prints the overview.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $version = is_file(FRASM_ROOT_DIR . '/.frasm-version')
            ? (json_decode((string)file_get_contents(FRASM_ROOT_DIR . '/.frasm-version'), true)['commit'] ?? null)
            : null;

        $output->table(['Setting', 'Value'], [
            ['Frasm', Frasm::VERSION . ($version ? " ({$version})" : '')],
            ['PHP', PHP_VERSION . ' (' . PHP_SAPI . ')'],
            ['Project root', FRASM_ROOT_DIR],
            ['Debug', (bool)Config::get('app.debug', false) ? 'on' : 'off'],
            ['App key', (string)Config::get('app.key', '') !== '' ? 'set' : 'MISSING (php bin/frasm key:generate)'],
            ['Database', (string)Config::get('database.connections.mysql.database', '')],
            ['Core modules', implode(', ', Migrator::enabledModules())],
            ['Route cache', $this->routeCache->isEnabled() ? (is_file($this->routeCache->path()) ? 'enabled, built' : 'enabled, not built') : 'disabled'],
            ['Log directory', (string)Config::get('logging.path', '')],
        ]);

        return 0;
    }
}
