<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\DB;
use Core\DB\Migrator;
use Core\Testing\TestRunner;

/**
 * @file TestCommand.php
 * @brief Runs the application tests (app/Tests) against the test databases.
 */

/**
 * @class TestCommand
 * @brief Switches the configuration to `testing`, prepares the test databases and runs the tests.
 *
 * Everything under the configuration key `testing` (usually in config/local.php) is merged over the
 * configuration first, e.g. the test databases:
 *
 *     'testing' => ['database' => ['connections' => [
 *         'blog' => ['database' => 'test_app_blog', 'username' => 'test_app_blog', 'password' => '…'],
 *     ]]],
 *
 * A connection whose database stays the same as outside the tests is disabled, so tests can never
 * read or change real data; the command refuses to run when a test database equals a real one by
 * explicit configuration. The test databases are migrated before the run (--fresh wipes them first).
 */
final class TestCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'test';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Run the application tests (app/Tests) against the test databases';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['filter?' => 'Run only tests whose "Directory\\ClassTest::testMethod" contains this text (e.g. blog)'];
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'fresh' => 'Wipe the test databases and migrate them again before the run',
            'stop'  => 'Stop at the first failure',
        ];
    }

    /**
     * @brief Returns additional help.
     *
     * @return string
     */
    public function help(): string
    {
        return "Tests are classes extending Core\\Testing\\TestCase in app/Tests (files named ...Test.php,\n"
            . "namespace App\\Tests\\...). Configure test databases under 'testing' in config/local.php;\n"
            . "connections without one are disabled during the tests.";
    }

    /**
     * @brief Runs the tests.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int 0 when all tests pass.
     */
    public function handle(Input $input, Output $output): int
    {
        $enabled = $this->switchToTesting($output);
        if ($enabled === null) {
            return 1;
        }

        foreach (Migrator::connections() as $connection) {
            if (!in_array($connection, $enabled, true)) {
                continue;
            }
            $migrator = new Migrator($connection);
            if ($input->flag('fresh')) {
                $migrator->wipe();
            }
            $executed = $migrator->up();
            if ($executed !== [] || $input->flag('fresh')) {
                $output->comment("[{$connection} → {$migrator->database()}] prepared (" . count($executed) . ' migrations)');
            }
        }

        $runner = new TestRunner($output);
        $classes = $runner->discover((string)Config::get('testing.path', FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Tests'));
        if ($classes === []) {
            $output->warning('No tests found in app/Tests.');
            return 0;
        }

        $output->line();
        return $runner->run($classes, $input->argument('filter'), $input->flag('stop')) ? 0 : 1;
    }

    /**
     * @brief Merges the `testing` configuration and disables connections without a test database.
     *
     * @param Output $output Console output.
     * @return list<string>|null Connections with a test database, or null when the configuration is unsafe.
     */
    private function switchToTesting(Output $output): ?array
    {
        $real = [];
        foreach (DB::connectionNames() as $name) {
            $real[$name] = (string)Config::get("database.connections.{$name}.database", '');
        }

        $testing = Config::get('testing', []);
        Config::merge(['app' => ['env' => 'testing', 'debug' => true]]);
        if (is_array($testing)) {
            Config::merge(array_diff_key($testing, ['path' => true]));
        }
        DB::disconnect();

        $enabled = [];
        foreach ($real as $name => $database) {
            $test = (string)Config::get("database.connections.{$name}.database", '');
            $explicit = is_array($testing) && isset($testing['database']['connections'][$name]['database']);

            if ($explicit && $test === $database) {
                $output->error("The test database of connection '{$name}' is the real database '{$database}'. Refusing to run.");
                return null;
            }
            if ($test === '' || $test === $database) {
                Config::set("database.connections.{$name}.disabled", "no test database during tests (set testing.database.connections.{$name})");
                $output->comment("[{$name}] no test database – disabled");
                continue;
            }
            $enabled[] = $name;
        }

        return $enabled;
    }
}
