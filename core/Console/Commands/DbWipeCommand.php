<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\Migrator;

/**
 * @file DbWipeCommand.php
 * @brief Drops every table of the configured database.
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
        return 'Drop ALL tables of the configured database';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return ['force' => 'Do not ask for confirmation'];
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
        $database = (string)Config::get('database.connections.mysql.database', '');
        if (!$this->confirmed($input, $output, "Drop ALL tables of database '{$database}'?")) {
            return 1;
        }

        (new Migrator())->wipe();
        $output->success("All tables of '{$database}' dropped");

        return 0;
    }
}
