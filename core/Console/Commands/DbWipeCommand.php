<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;

/**
 * @file DbWipeCommand.php
 * @brief Drops every table of the databases of all (or one) connections.
 */
final class DbWipeCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'db:wipe';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Drop ALL tables of the configured databases';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'connection=' => 'Wipe only this connection',
            'force'       => 'Do not ask for confirmation',
        ];
    }

    /**
     * @brief Drops the tables after confirmation.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $migrators = Migrator::forConnections($input->option('connection'));
        $databases = implode(', ', array_map(fn(Migrator $migrator): string => "'{$migrator->database()}'", $migrators));
        if (!$this->confirmed($input, $output, "Drop ALL tables of database(s) {$databases}?")) {
            return 1;
        }

        foreach ($migrators as $migrator) {
            $migrator->wipe();
            $output->success("All tables of '{$migrator->database()}' dropped");
        }

        return 0;
    }
}
