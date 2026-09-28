<?php

declare(strict_types=1);

namespace Core\DB;

use Core\Config\Config;
use Core\Exceptions\DatabaseException;

/**
 * @file Migrator.php
 * @brief Applies the core schema and runs application database migrations.
 */

/**
 * @class Migrator
 * @brief Maintains the framework schema (core/DB/schema.sql) and application migrations.
 *
 * The core schema is a single SQL file split into module sections ("-- @module <name>").
 * It is applied idempotently (CREATE TABLE IF NOT EXISTS) on every migrate run, for enabled
 * modules only. Application migrations (database.migrations_path) are PHP files returning an
 * object with up()/down(); they are tracked in `frasm_migrations` and rolled back in batches.
 */
class Migrator
{
    /**
     * @var array<string, string|null> Core schema modules mapped to their enabling config key (null = always enabled).
     */
    public const CORE_MODULES = [
        'core' => null,
        'auth' => 'auth.enabled',
        'push' => 'push.enabled',
    ];

    /**
     * @var string Migration tracking table.
     */
    protected const MIGRATIONS_TABLE = 'frasm_migrations';

    /**
     * @var DB Active database connection.
     */
    protected DB $db;

    /**
     * @var list<string> Registered directories containing application migration files.
     */
    protected array $paths = [];

    /**
     * @brief Registers the application migration directory.
     *
     * @throws DatabaseException If the database connection cannot be established.
     */
    public function __construct()
    {
        $this->db = DB::getInstance();

        $appMigrations = (string)Config::get(
            'database.migrations_path',
            FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations'
        );
        if (is_dir($appMigrations)) {
            $this->addPath($appMigrations);
        }
    }

    /**
     * @brief Adds a directory containing migration files into the runner pool.
     *
     * @param string $path Directory path; ignored when it does not exist or is already registered.
     * @return void
     */
    public function addPath(string $path): void
    {
        $real = realpath($path);
        if ($real && !in_array($real, $this->paths, true)) {
            $this->paths[] = $real;
        }
    }

    /**
     * @brief Returns the path of the core schema file.
     *
     * @return string
     */
    public static function schemaPath(): string
    {
        return FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'DB' . DIRECTORY_SEPARATOR . 'schema.sql';
    }

    /**
     * @brief Returns names of core modules whose schema section should be applied.
     *
     * @return list<string>
     */
    public static function enabledModules(): array
    {
        $modules = [];
        foreach (self::CORE_MODULES as $module => $enabledKey) {
            if ($enabledKey === null || (bool)Config::get($enabledKey, $module === 'auth')) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * @brief Applies the core schema sections of all enabled modules (idempotent).
     *
     * @return list<string> Applied module names.
     * @throws DatabaseException If the schema file is missing or a statement fails.
     */
    public function applyCoreSchema(): array
    {
        $sections = self::parseSchema(self::schemaPath());
        $applied = [];

        foreach (self::enabledModules() as $module) {
            foreach ($sections[$module] ?? [] as $statement) {
                if ($statement['unless_column'] !== null && $this->columnExists(...$statement['unless_column'])) {
                    continue;
                }
                $this->db->query($statement['sql']);
            }
            $applied[] = $module;
        }

        return $applied;
    }

    /**
     * @brief Splits the schema file into statements grouped by module.
     *
     * "-- @module <name>" starts a module section. "-- @unless-column <table>.<column>" makes the next
     * statement run only when that column does not exist yet: the portable way to add a column to an
     * existing table (MySQL has no ADD COLUMN IF NOT EXISTS).
     *
     * @param string $path Schema file.
     * @return array<string, list<array{sql: string, unless_column: array{0: string, 1: string}|null}>>
     * @throws DatabaseException If the file cannot be read or a directive is malformed.
     */
    public static function parseSchema(string $path): array
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new DatabaseException("Core schema file not found: '{$path}'.");
        }

        $sections = [];
        $module = 'core';
        $buffer = '';
        $unlessColumn = null;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^--\s*@module\s+([a-z0-9_]+)\s*$/i', $trimmed, $matches)) {
                $module = strtolower($matches[1]);
                continue;
            }

            if (preg_match('/^--\s*@unless-column\s+(.*)$/i', $trimmed, $matches)) {
                if (!preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)$/i', trim($matches[1]), $parts)) {
                    throw new DatabaseException("Malformed schema directive '{$trimmed}' (expected @unless-column <table>.<column>).");
                }
                $unlessColumn = [$parts[1], $parts[2]];
                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $buffer .= $line . "\n";

            if (str_ends_with($trimmed, ';')) {
                $sections[$module][] = ['sql' => rtrim(trim($buffer), ';'), 'unless_column' => $unlessColumn];
                $buffer = '';
                $unlessColumn = null;
            }
        }

        if (trim($buffer) !== '') {
            $sections[$module][] = ['sql' => trim($buffer), 'unless_column' => $unlessColumn];
        }

        return $sections;
    }

    /**
     * @brief Checks whether a column exists in the current database.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @return bool
     */
    private function columnExists(string $table, string $column): bool
    {
        return $this->db->selectValue(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== null;
    }

    /**
     * @brief Ensures the migration tracking table exists.
     *
     * @return void
     * @throws DatabaseException On query failure.
     */
    public function ensureMigrationTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . self::MIGRATIONS_TABLE . "` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT(11) NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @brief Applies the core schema and executes all pending application migrations in order.
     *
     * Note: MySQL/MariaDB DDL statements cause an implicit commit, so a failing multi-statement
     * migration cannot be rolled back automatically. Keep one schema change per migration.
     *
     * @return list<string> Names of executed application migrations.
     * @throws DatabaseException If a migration file is malformed or its execution fails.
     */
    public function up(): array
    {
        $this->applyCoreSchema();
        $this->ensureMigrationTable();

        $applied = array_flip(array_column(
            $this->db->select("SELECT `migration` FROM `" . self::MIGRATIONS_TABLE . "`"),
            'migration'
        ));

        $files = $this->collectMigrationFiles();
        $batch = (int)$this->db->selectValue("SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `" . self::MIGRATIONS_TABLE . "`");
        $executed = [];

        foreach ($files as $name => $filePath) {
            if (isset($applied[$name])) {
                continue;
            }

            $migration = require $filePath;
            if (!is_object($migration) || !method_exists($migration, 'up')) {
                throw new DatabaseException("Migration '{$name}' must return an object implementing up().");
            }

            $this->db->transactional(function () use ($migration, $name, $batch): void {
                $migration->up();
                $this->db->insert(self::MIGRATIONS_TABLE, [
                    'migration' => $name,
                    'batch'     => $batch,
                ]);
            });

            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * @brief Rolls back the latest batch of application migrations (the core schema is not affected).
     *
     * @return list<string> Names of rolled back migrations.
     * @throws DatabaseException If a migration of the batch is missing on disk or does not implement down().
     */
    public function down(): array
    {
        $this->ensureMigrationTable();

        $lastBatch = (int)$this->db->selectValue("SELECT MAX(`batch`) FROM `" . self::MIGRATIONS_TABLE . "`");
        if ($lastBatch === 0) {
            return [];
        }

        $records = $this->db->select(
            "SELECT `migration` FROM `" . self::MIGRATIONS_TABLE . "` WHERE `batch` = ? ORDER BY `id` DESC",
            [$lastBatch]
        );

        $rolledBack = [];
        $files = $this->collectMigrationFiles();

        foreach ($records as $record) {
            $name = (string)$record['migration'];
            if (!isset($files[$name])) {
                throw new DatabaseException("Cannot roll back '{$name}': migration file not found in any registered path.");
            }

            $migration = require $files[$name];
            if (!is_object($migration) || !method_exists($migration, 'down')) {
                throw new DatabaseException("Cannot roll back '{$name}': migration does not implement down().");
            }

            $this->db->transactional(function () use ($migration, $name): void {
                $migration->down();
                $this->db->delete(self::MIGRATIONS_TABLE, '`migration` = ?', [$name]);
            });

            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * @brief Drops all existing tables in the database.
     *
     * @return void
     * @throws DatabaseException On query failure.
     */
    public function wipe(): void
    {
        $tables = $this->db->select("SHOW TABLES");
        if (empty($tables)) {
            return;
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($tables as $row) {
            $this->db->query("DROP TABLE IF EXISTS " . $this->db->quoteIdentifier((string)reset($row)));
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    /**
     * @brief Scans all registered paths and returns an ordered map of [filename => full_path].
     *
     * @return array<string, string>
     * @throws DatabaseException If two registered paths contain a migration with the same name.
     */
    protected function collectMigrationFiles(): array
    {
        $allFiles = [];

        foreach ($this->paths as $dir) {
            $found = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            foreach ($found as $file) {
                $base = basename($file, '.php');
                if (isset($allFiles[$base])) {
                    throw new DatabaseException("Duplicate migration name '{$base}' found in '{$allFiles[$base]}' and '{$file}'.");
                }
                $allFiles[$base] = $file;
            }
        }

        // Natural sort ensures timestamp prefixes execute in chronological order
        ksort($allFiles, SORT_NATURAL);

        return $allFiles;
    }
}
