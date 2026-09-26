<?php

declare(strict_types=1);

/**
 * @file db.php
 * @brief General database migration and management console tool.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$action = $argv[1] ?? 'help';
$seedAfter = in_array('--seed', $argv, true);

try {
    $migrator = new \Core\DB\Migrator();

    switch ($action) {
        case 'migrate':
        case 'up':
            echo "Running pending migrations...\n";
            $executed = $migrator->up();
            echo "  ✔ Core schema applied (modules: " . implode(', ', \Core\DB\Migrator::enabledModules()) . ")\n";
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
            echo "  ✔ Core schema applied (modules: " . implode(', ', \Core\DB\Migrator::enabledModules()) . ")\n";
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
            echo "  migrate           Apply core schema (core/DB/schema.sql) and run pending app migrations\n";
            echo "  rollback          Rollback the last application migration batch\n";
            echo "  wipe              Drop all database tables completely\n";
            echo "  reset [--seed]    Wipe all tables, re-run all migrations, optionally seed\n";
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error: " . $e->getMessage() . "\n");
    exit(1);
}