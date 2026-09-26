<?php

declare(strict_types=1);

namespace Core\Config;

use Core\Exceptions\CoreException;

/**
 * @file Config.php
 * @brief Centralized configuration repository for the Frasm framework.
 */

/**
 * @class Config
 * @brief Global configuration manager providing dot-notation access to system settings.
 *
 * Reads PHP configuration files returning associative arrays, caches them in memory,
 * and allows retrieval and manipulation via dot-delimited key paths (e.g., 'app.debug',
 * 'routing.attributes.enabled', 'auth.guards.web').
 */
class Config
{
    /**
     * @var array<string, mixed> Cached repository of loaded configuration settings.
     */
    private static array $items = [];

    /**
     * @var bool Flag indicating whether configurations have been loaded.
     */
    private static bool $isLoaded = false;

    /**
     * @brief Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * @brief Scans a directory and loads all PHP configuration files into memory.
     *
     * Every file returning an associative array is stored under its filename key
     * (e.g., database.php -> $items['database']). If a 'local.php' file exists,
     * its values will override previous settings recursively.
     *
     * @param string $directoryPath Path to the configuration directory.
     * @return void
     * @throws CoreException If directory does not exist or cannot be read.
     */
    public static function load(string $directoryPath): void
    {
        $realPath = realpath($directoryPath);

        if ($realPath === false || !is_dir($realPath)) {
            throw new CoreException("Configuration directory not found: '{$directoryPath}'");
        }

        $files = glob($realPath . DIRECTORY_SEPARATOR . '*.php') ?: [];

        foreach ($files as $file) {
            $key = basename($file, '.php');

            // Skip local override file; apply it last
            if ($key === 'local') {
                continue;
            }

            $content = require $file;

            if (is_array($content)) {
                self::$items[$key] = $content;
            }
        }

        // Apply local environment overrides if present
        $localOverride = $realPath . DIRECTORY_SEPARATOR . 'local.php';
        if (is_file($localOverride)) {
            $localContent = require $localOverride;
            if (is_array($localContent)) {
                self::$items = array_replace_recursive(self::$items, $localContent);
            }
        }

        self::$isLoaded = true;
    }

    /**
     * @brief Retrieves a configuration value using dot notation.
     *
     * @param string $key Dot-delimited key identifier (e.g. 'auth.default.guard').
     * @param mixed $default Fallback value returned when key is not found.
     * @return mixed Configured value or default fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @brief Sets a configuration value at runtime using dot notation.
     *
     * Useful for testing environments or dynamically generated paths.
     *
     * @param string $key Dot-delimited key identifier.
     * @param mixed $value Value to set.
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * @brief Checks if a specific configuration key exists.
     *
     * @param string $key Dot-delimited key identifier.
     * @return bool True if key exists, false otherwise.
     */
    public static function has(string $key): bool
    {
        $segments = explode('.', $key);
        $current = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @brief Returns all loaded configuration items.
     *
     * @return array<string, mixed> Complete configuration repository.
     */
    public static function all(): array
    {
        return self::$items;
    }

    /**
     * @brief Clears the internal configuration cache.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$items = [];
        self::$isLoaded = false;
    }

    /**
     * @brief Checks whether the configuration files have been loaded.
     *
     * @return bool
     */
    public static function isLoaded(): bool
    {
        return self::$isLoaded;
    }
}