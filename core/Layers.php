<?php

declare(strict_types=1);

namespace Core;

/**
 * @file Layers.php
 * @brief Source layers of a project: the application (app/, namespace App) and an optional site layer (site/,
 *        namespace Site) on top of it.
 */

/**
 * @class Layers
 * @brief Lists the source directories in the order of priority and finds files in them.
 *
 * A project can be built from an application maintained elsewhere (installed into app/ and replaced by its updates)
 * and the project's own additions in site/, which updates never touch. The site layer has the same structure as app/
 * and the namespace Site:
 *
 * - Views, Assets, Lang: a file in site/ replaces the same file of app/ (views, error pages, styles, dictionaries
 *   – a dictionary of site/ is merged over the one of app/);
 * - Controllers, Tasks, Commands, Tests, database/migrations: those of site/ are added to those of app/;
 * - bootstrap.php: site/bootstrap.php runs after app/bootstrap.php.
 *
 * Without a site/ directory everything works as before (only app/).
 */
final class Layers
{
    /**
     * @brief Prevents instantiation.
     */
    private function __construct()
    {
    }

    /**
     * @brief Returns the layers present, the one with the highest priority first.
     *
     * @return list<array{namespace: string, path: string}>
     */
    public static function all(): array
    {
        $layers = [];
        $site = self::siteDir();
        if ($site !== null && is_dir($site)) {
            $layers[] = ['namespace' => 'Site', 'path' => $site];
        }
        $layers[] = ['namespace' => 'App', 'path' => self::appDir()];

        return $layers;
    }

    /**
     * @brief Returns the existing directories of a part of the layers (e.g. 'Views'), the highest priority first.
     *
     * @param string $part Relative directory ('Views', 'Assets', 'Lang', 'database/migrations', ...).
     * @return list<string>
     */
    public static function directories(string $part): array
    {
        $directories = [];
        foreach (self::all() as $layer) {
            $directory = $layer['path'] . DIRECTORY_SEPARATOR . self::native($part);
            if (is_dir($directory)) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    /**
     * @brief Finds a file in the layers: the one of the layer with the highest priority.
     *
     * @param string $relative Path inside a layer, e.g. 'Views/users/index.php'.
     * @return string|null Absolute path, or null when no layer has it.
     */
    public static function find(string $relative): ?string
    {
        foreach (self::all() as $layer) {
            $file = $layer['path'] . DIRECTORY_SEPARATOR . self::native($relative);
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @brief Returns the classes of a part of the layers named by a suffix (e.g. controllers, commands), the
     *        namespace following the layer and the nesting of the directories.
     *
     * @param string $part Relative directory, e.g. 'Commands'.
     * @param string $suffix File name suffix, e.g. 'Command.php' ('.php' = every file).
     * @param bool $recursive Look into subdirectories too.
     * @return array<string, string> Class => file.
     */
    public static function classes(string $part, string $suffix = '.php', bool $recursive = false): array
    {
        $classes = [];
        foreach (array_reverse(self::all()) as $layer) {
            $root = $layer['path'] . DIRECTORY_SEPARATOR . self::native($part);
            if (!is_dir($root)) {
                continue;
            }
            $files = $recursive
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS))
                : new \ArrayIterator(array_map(static fn(string $file): \SplFileInfo => new \SplFileInfo($file), glob($root . DIRECTORY_SEPARATOR . '*.php') ?: []));
            foreach ($files as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), $suffix)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $class = $layer['namespace'] . '\\' . str_replace('/', '\\', $part) . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                $classes[$class] = $file->getPathname();
            }
        }
        ksort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * @brief Directory of the application layer.
     *
     * @return string
     */
    public static function appDir(): string
    {
        return defined('FRASM_APP_DIR') ? FRASM_APP_DIR : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app';
    }

    /**
     * @brief Directory of the site layer (it may not exist).
     *
     * @return string|null
     */
    public static function siteDir(): ?string
    {
        return defined('FRASM_SITE_DIR') ? (FRASM_SITE_DIR === '' ? null : FRASM_SITE_DIR) : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'site';
    }

    /**
     * @brief Converts '/' of a relative path to the directory separator.
     *
     * @param string $path Relative path.
     * @return string
     */
    private static function native(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, trim($path, '/'));
    }
}
