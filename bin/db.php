<?php

declare(strict_types=1);

/**
 * @file db.php
 * @brief General database migration and management console tool.
 */

define('FRASM_ROOT_DIR', dirname(__DIR__));
define('FRASM_CORE_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'core');
define('FRASM_APP_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'app');
define('FRASM_CONFIG_DIR', FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'config');

require_once FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR . 'Autoloader.php';

$autoloader = new \Core\Autoload\Autoloader();
$autoloader->addNamespace('Core', FRASM_CORE_DIR);
$autoloader->addNamespace('App', FRASM_APP_DIR);
$autoloader->register();

\Core\Config\Config::load(FRASM_CONFIG_DIR);

$action = $argv[1] ?? 'help';
$seedAfter = in_array('--seed', $argv, true);

$migrator = new \Core\DB\Migrator();

try {
    switch ($action) {
        case 'migrate':
        case 'up':
            echo "Running pending migrations...\n";
            $executed = $migrator->up();
            if (empty($executed)) {
                echo "Nothing to migrate. Database is up to date.\n";
            } else {
                foreach ($executed as $mig) {
                    echo "  ✔ Migrated: {$mig}\n";
                }
            }
            break;

        case 'rollback':
        case 'down':
            echo "Rolling back last migration batch...\n";
            $rolled = $migrator->down();
            if (empty($rolled)) {
                echo "No migrations to roll back.\n";
            } else {
                foreach ($rolled as $mig) {
                    echo "  ✔ Rolled back: {$mig}\n";
                }
            }
            break;

        case 'wipe':
            echo "Dropping all tables...\n";
            $migrator->wipe();
            echo "✔ Database wiped clean.\n";
            break;

        case 'reset':
            echo "Wiping and re-running all migrations...\n";
            $migrator->wipe();
            $executed = $migrator->up();
            foreach ($executed as $mig) {
                echo "  ✔ Migrated: {$mig}\n";
            }
            echo "✔ Database successfully reset to fresh state.\n";

            if ($seedAfter && file_exists(__DIR__ . '/seed-admin.php')) {
                echo "Running optional domain seed...\n";
                require __DIR__ . '/seed-admin.php';
            }
            break;

        default:
            echo "Frasm Migration Tool\n";
            echo "--------------------\n";
            echo "Usage: php bin/db.php <command> [--seed]\n\n";
            echo "Commands:\n";
            echo "  migrate           Run all pending migrations\n";
            echo "  rollback          Rollback the last migration batch\n";
            echo "  wipe              Drop all database tables completely\n";
            echo "  reset [--seed]    Wipe all tables, re-run all migrations, optionally seed\n";
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error: " . $e->getMessage() . "\n");
    exit(1);
}