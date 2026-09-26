<?php

declare(strict_types=1);

namespace Core\Autoload;

/**
 * @file Autoloader.php
 * @brief High-performance PSR-4 compliant class loader for the Frasm framework.
 */

/**
 * @class Autoloader
 * @brief Manages namespace-to-directory mappings and registers an SPL autoload callback.
 *
 * Implements the PSR-4 recommendation by resolving fully qualified class names
 * through registered namespace prefixes and corresponding base directories.
 */
class Autoloader
{
    /**
     * @var array<string, list<string>> Map of namespace prefixes to base directory paths.
     */
    protected array $prefixes = [];

    /**
     * @brief Registers the internal loadClass method into the SPL autoloader stack.
     *
     * @param bool $prepend If true, prepends the autoloader onto the stack ahead of others.
     * @return void
     */
    public function register(bool $prepend = false): void
    {
        spl_autoload_register([$this, 'loadClass'], true, $prepend);
    }

    /**
     * @brief Unregisters this autoloader instance from the SPL autoloader stack.
     *
     * @return void
     */
    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'loadClass']);
    }

    /**
     * @brief Maps a base directory to a namespace prefix.
     *
     * @param string $prefix Namespace prefix (e.g. 'Core\\').
     * @param string $baseDir Base directory path corresponding to the namespace prefix.
     * @param bool $prepend If true, prepends the base directory to be searched first.
     * @return self
     */
    public function addNamespace(string $prefix, string $baseDir, bool $prepend = false): self
    {
        // Normalize namespace prefix with trailing backslash
        $prefix = trim($prefix, '\\') . '\\';

        // Normalize base directory with trailing directory separator
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!isset($this->prefixes[$prefix])) {
            $this->prefixes[$prefix] = [];
        }

        if ($prepend) {
            array_unshift($this->prefixes[$prefix], $baseDir);
        } else {
            $this->prefixes[$prefix][] = $baseDir;
        }

        return $this;
    }

    /**
     * @brief Attempts to load the source file for the given fully qualified class name.
     *
     * @param string $className Fully qualified class name.
     * @return string|false The path to the loaded file on success, or false on failure.
     */
    public function loadClass(string $className): string|false
    {
        $prefix = $className;

        // Work backward through namespace segments to find matching registered prefix
        while (false !== ($pos = strrpos($prefix, '\\'))) {
            $prefix = substr($className, 0, $pos + 1);
            $relativeClass = substr($className, $pos + 1);

            $mappedFile = $this->loadMappedFile($prefix, $relativeClass);
            if ($mappedFile !== false) {
                return $mappedFile;
            }

            // Remove trailing backslash for next iteration
            $prefix = rtrim($prefix, '\\');
        }

        return false;
    }

    /**
     * @brief Searches registered base directories for a mapped file and requires it if found.
     *
     * @param string $prefix Namespace prefix.
     * @param string $relativeClass Relative class path segments.
     * @return string|false Path to the required file, or false if not located.
     */
    protected function loadMappedFile(string $prefix, string $relativeClass): string|false
    {
        if (!isset($this->prefixes[$prefix])) {
            return false;
        }

        // Convert namespace separators to directory separators
        $relativeFilePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        foreach ($this->prefixes[$prefix] as $baseDir) {
            $filePath = $baseDir . $relativeFilePath;

            if ($this->requireFile($filePath)) {
                return $filePath;
            }
        }

        return false;
    }

    /**
     * @brief Safely includes a file if it exists on disk.
     *
     * @param string $file Absolute or relative path to file.
     * @return bool True if file exists and was loaded, false otherwise.
     */
    protected function requireFile(string $file): bool
    {
        if (is_file($file)) {
            require $file;
            return true;
        }

        return false;
    }
}