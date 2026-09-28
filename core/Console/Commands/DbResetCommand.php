<?php

declare(strict_types=1);

namespace Core\Console\Commands;

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
        return 'Drop all tables and migrate again, all connections (development only)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'connection=' => 'Reset only this connection',
            'seed'        => 'Seed the default administrator afterwards',
            'force'       => 'Do not ask for confirmation',
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
        $migrators = Migrator::forConnections($input->option('connection'));
        $databases = implode(', ', array_map(fn(Migrator $migrator): string => "'{$migrator->database()}'", $migrators));
        if (!$this->confirmed($input, $output, "Drop ALL tables of database(s) {$databases} and migrate again?")) {
            return 1;
        }

        foreach ($migrators as $migrator) {
            $label = "[{$migrator->connection()} → {$migrator->database()}]";
            $migrator->wipe();
            $output->success("{$label} All tables dropped");

            $executed = $migrator->up();
            if ($migrator->isDefault()) {
                $output->success("{$label} Core schema applied (modules: " . implode(', ', Migrator::enabledModules()) . ')');
            }
            foreach ($executed as $migration) {
                $output->success("{$label} Migrated {$migration}");
            }
        }

        // The administrator lives in the default database
        $seed = $input->flag('seed') && array_filter($migrators, fn(Migrator $migrator): bool => $migrator->isDefault()) !== [];
        return $seed ? $this->seed->handle($input, $output) : 0;
    }
}
