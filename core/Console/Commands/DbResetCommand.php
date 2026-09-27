<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;

/**
 * @file DbResetCommand.php
 * @brief Recreates the database from scratch (development).
 */
final class DbResetCommand extends Command
{
    /**
     * @brief DbResetCommand constructor.
     *
     * @param DbSeedCommand $seed Admin seeding command (reused for --seed).
     */
    public function __construct(private readonly DbSeedCommand $seed)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'db:reset';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Drop all tables and migrate again (development only)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'seed'  => 'Seed the default administrator afterwards',
            'force' => 'Do not ask for confirmation',
        ];
    }

    /**
     * @brief Wipes, migrates and optionally seeds.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $database = (string)Config::get('database.connections.mysql.database', '');
        if (!$this->confirmed($input, $output, "Drop ALL tables of database '{$database}' and migrate again?")) {
            return 1;
        }

        $migrator = new Migrator();
        $migrator->wipe();
        $output->success('All tables dropped');

        $executed = $migrator->up();
        $output->success('Core schema applied (modules: ' . implode(', ', Migrator::enabledModules()) . ')');
        foreach ($executed as $migration) {
            $output->success("Migrated {$migration}");
        }

        return $input->flag('seed') ? $this->seed->handle($input, $output) : 0;
    }
}
