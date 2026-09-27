<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;

/**
 * @file DbRollbackCommand.php
 * @brief Rolls back the last batch of application migrations.
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
        return 'Roll back the last batch of migrations (the core schema is not affected)';
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
        $rolledBack = (new Migrator())->down();

        if ($rolledBack === []) {
            $output->success('Nothing to roll back');
        }
        foreach ($rolledBack as $migration) {
            $output->success("Rolled back {$migration}");
        }

        return 0;
    }
}
