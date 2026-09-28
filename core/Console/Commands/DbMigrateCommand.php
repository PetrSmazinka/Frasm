<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;

/**
 * @file DbMigrateCommand.php
 * @brief Applies the core schema and pending application migrations of every connection.
 */
final class DbMigrateCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'db:migrate';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Apply the core schema and run pending migrations (all connections)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return ['connection=' => 'Migrate only this connection'];
    }

    /**
     * @brief Runs the migrations.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        foreach (Migrator::forConnections($input->option('connection')) as $migrator) {
            $label = "[{$migrator->connection()} → {$migrator->database()}]";
            $executed = $migrator->up();

            if ($migrator->isDefault()) {
                $output->success("{$label} Core schema applied (modules: " . implode(', ', Migrator::enabledModules()) . ')');
            }
            if ($executed === []) {
                $output->success("{$label} No pending migrations");
            }
            foreach ($executed as $migration) {
                $output->success("{$label} Migrated {$migration}");
            }
        }

        return 0;
    }
}
