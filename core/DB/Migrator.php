<?php

declare(strict_types=1);

namespace Core\DB;

use Core\Config\Config;
use Core\Exceptions\DatabaseException;

/**
 * @file Migrator.php
 * @brief Multi-path database migration runner supporting core module and application migrations.
 */
class Migrator
{
    protected DB $db;

    /**
     * @var list<string> Registered directories containing migration files.
     */
    protected array $paths = [];

    public function __construct()
    {
        $this->db = DB::getInstance();

        // 1. Register Core/Internal module migrations if enabled
        if ((bool)Config::get('auth.enabled', true)) {
            $authMigrations = FRASM_CORE_DIR . DIRECTORY_SEPARATOR . 'Auth' . DIRECTORY_SEPARATOR . 'migrations';
            if (is_dir($authMigrations)) {
                $this->addPath($authMigrations);
            }
        }

        // 2. Register Application domain migrations (always executed after core)
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
     */
    public function addPath(string $path): void
    {
        $real = realpath($path);
        if ($real && !in_array($real,$this->paths, true)) {
            $this->paths[] =$real;
        }
    }

    /**
     * @brief Ensures internal framework migration tracking table exists.
     */
    public function ensureMigrationTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `_migrations` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT(11) NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * @brief Executes all pending migrations across all registered paths in chronological order.
     *
     * @return list<string>
     */
    public function up(): array
    {
        $this->ensureMigrationTable();

        $applied = array_column($this->db->select("SELECT `migration` FROM `_migrations`"),
            'migration'
        );

        $files =$this->collectMigrationFiles();
        $batch = (int)$this->db->selectValue("SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `_migrations`");
        $executed = [];

        foreach ($files as $name =>$filePath) {
            if (in_array($name,$applied, true)) {
                continue;
            }

            $migration = require$filePath;
            if (!is_object($migration) || !method_exists($migration, 'up')) {
                throw new DatabaseException("Migration '{$name}' must return an object implementing up().");
            }

            $this->db->transactional(function () use ($migration, $name,$batch): void {
                $migration->up();$this->db->insert('_migrations', [
                    'migration' => $name,
                    'batch'     => $batch,
                ]);
            });

            $executed[] =$name;
        }

        return $executed;
    }

    /**
     * @brief Rolls back the latest batch of migrations.
     *
     * @return list<string>
     */
    public function down(): array
    {
        $this->ensureMigrationTable();

        $lastBatch = (int)$this->db->selectValue("SELECT MAX(`batch`) FROM `_migrations`");
        if ($lastBatch === 0) {
            return [];
        }

        $records =$this->db->select(
            "SELECT `migration` FROM `_migrations` WHERE `batch` = ? ORDER BY `id` DESC",
            [$lastBatch]
        );

        $rolledBack = [];
        $files =$this->collectMigrationFiles();

        foreach ($records as$record) {
            $name = (string)$record['migration'];
            if (!isset($files[$name])) {
                continue;
            }

            $migration = require $files[$name];
            if (method_exists($migration, 'down')) {$this->db->transactional(function () use ($migration,$name): void {
                    $migration->down();$this->db->delete('_migrations', '`migration` = ?', [$name]);
                });
            }

            $rolledBack[] =$name;
        }

        return $rolledBack;
    }

    /**
     * @brief Drops all existing tables in the database.
     */
    public function wipe(): void
    {
        $tables =$this->db->select("SHOW TABLES");
        if (empty($tables)) {
            return;
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0;");
        foreach ($tables as $row) {$table = reset($row);$this->db->query("DROP TABLE IF EXISTS `{$table}`;");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1;");
    }

    /**
     * @brief Scans all registered paths and returns an ordered map of [filename => full_path].
     *
     * @return array<string, string>
     */
    protected function collectMigrationFiles(): array
    {
        $allFiles = [];

        foreach ($this->paths as$dir) {
            $found = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
            foreach ($found as $file) {$base = basename($file, '.php');$allFiles[$base] =$file;
            }
        }

        // Natural sort ensures timestamps (e.g. 0001_core... before 2026_01...) execute correctly
        ksort($allFiles, SORT_NATURAL);

        return $allFiles;
    }
}