<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;

/**
 * @file DbRollbackCommand.php
 * @brief Rolls back the latest batch of migrations of one connection.
 */
final class DbRollbackCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'db:rollback';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Roll back the last batch of migrations of a connection (the core schema is not affected)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return ['connection=' => 'Connection to roll back (default: the default connection)'];
    }

    /**
     * @brief Rolls back.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $migrator = new Migrator($input->option('connection'));
        $label = "[{$migrator->connection()} → {$migrator->database()}]";
        $rolledBack = $migrator->down();

        if ($rolledBack === []) {
            $output->success("{$label} Nothing to roll back");
        }
        foreach ($rolledBack as $migration) {
            $output->success("{$label} Rolled back {$migration}");
        }

        return 0;
    }
}
