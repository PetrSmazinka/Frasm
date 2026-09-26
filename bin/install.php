<?php

declare(strict_types=1);

/**
 * @file install.php
 * @brief Creates a new Frasm project or updates the framework files of an existing one.
 *
 * Runs from a downloaded copy of the framework (see install.sh), never from the target itself.
 *
 * Usage:
 *   php bin/install.php --target=/var/www/app [--no-example] [--web-user=www-data]
 *   php bin/install.php --target=/var/www/app --update [--migrate] [--web-user=www-data]
 *   Optional: --ref=<branch|tag> --commit=<sha> (recorded in .frasm-version)
 *
 * Ownership rules:
 *   - framework files (FRAMEWORK_DIRS, FRAMEWORK_FILES, public/js/frasm-*.js) are always replaced;
 *   - config/*.php defaults are copied only when missing (never overwritten);
 *   - app/, database/, storage/, config/local.php and .gitignore are created once and never touched again.
 */

const MIN_PHP_VERSION_ID = 80300;

/**
 * @var list<string> Directories owned by the framework (replaced as a whole).
 */
const FRAMEWORK_DIRS = ['core', 'bin'];

/**
 * @var list<string> Single files owned by the framework.
 */
const FRAMEWORK_FILES = [
    'public/index.php',
    'public/.htaccess',
    'public/frasm-sw.js',
    'Makefile',
    'install.sh',
];

/**
 * @var list<string> Required PHP extensions.
 */
const REQUIRED_EXTENSIONS = ['mysqli', 'openssl', 'mbstring', 'json'];

/**
 * @var array<string, string> Optional PHP extensions and the feature needing them.
 */
const OPTIONAL_EXTENSIONS = ['curl' => 'Web Push delivery', 'pcntl' => 'graceful queue worker shutdown', 'Zend OPcache' => 'performance'];

/**
 * @brief Prints a message to STDOUT.
 *
 * @param string $message Message.
 * @return void
 */
function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * @brief Prints an error and terminates.
 *
 * @param string $message Error message.
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, "✖ {$message}" . PHP_EOL);
    exit(1);
}

/**
 * @brief Parses --key=value and --flag arguments.
 *
 * @param list<string> $argv Raw arguments.
 * @return array<string, string|bool>
 */
function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches)) {
            fail("Unknown argument '{$argument}'.");
        }
        $options[$matches[1]] = $matches[2] ?? true;
    }

    return $options;
}

/**
 * @brief Verifies PHP version and extensions.
 *
 * @return void
 */
function checkRequirements(): void
{
    if (PHP_VERSION_ID < MIN_PHP_VERSION_ID) {
        fail('PHP 8.3 or newer is required (found ' . PHP_VERSION . ').');
    }

    $missing = array_filter(REQUIRED_EXTENSIONS, fn(string $ext): bool => !extension_loaded($ext));
    if ($missing !== []) {
        fail('Missing required PHP extensions: ' . implode(', ', $missing) . '.');
    }

    foreach (OPTIONAL_EXTENSIONS as $extension => $feature) {
        if (!extension_loaded($extension)) {
            out("  ! Optional extension '{$extension}' is missing ({$feature}).");
        }
    }
}

/**
 * @brief Recursively copies a directory.
 *
 * @param string $source Source directory.
 * @param string $target Target directory (created).
 * @return void
 */
function copyDirectory(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        fail("Cannot create directory '{$target}'.");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $destination = $target . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($source) + 1);
        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                fail("Cannot create directory '{$destination}'.");
            }
        } elseif (!copy($item->getPathname(), $destination)) {
            fail("Cannot copy '{$item->getPathname()}'.");
        }
    }
}

/**
 * @brief Recursively removes a directory.
 *
 * @param string $path Directory.
 * @return void
 */
function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

/**
 * @brief Replaces a framework directory: copy next to it, then swap by rename.
 *
 * @param string $source Source directory.
 * @param string $target Target directory.
 * @return void
 */
function replaceDirectory(string $source, string $target): void
{
    $new = $target . '.frasm-new';
    $old = $target . '.frasm-old';
    removeDirectory($new);
    removeDirectory($old);

    copyDirectory($source, $new);

    if (is_dir($target) && !rename($target, $old)) {
        fail("Cannot move '{$target}' aside.");
    }
    if (!rename($new, $target)) {
        is_dir($old) && rename($old, $target);
        fail("Cannot activate '{$target}'.");
    }
    removeDirectory($old);
}

/**
 * @brief Replaces a single file atomically (temp file + rename), preserving its permissions.
 *
 * @param string $source Source file.
 * @param string $target Target file.
 * @return void
 */
function replaceFile(string $source, string $target): void
{
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        fail("Cannot create directory '{$directory}'.");
    }

    $temp = $target . '.frasm-new';
    if (!copy($source, $temp)) {
        fail("Cannot copy '{$source}'.");
    }
    chmod($temp, fileperms($source) & 0777);
    if (!rename($temp, $target)) {
        fail("Cannot replace '{$target}'.");
    }
}

/**
 * @brief Creates config/local.php with a fresh app key and admin password.
 *
 * @param string $source Framework source directory.
 * @param string $target Project directory.
 * @return string|null Generated admin password, or null when local.php already existed.
 */
function createLocalConfig(string $source, string $target): ?string
{
    $file = $target . '/config/local.php';
    if (is_file($file)) {
        return null;
    }

    $template = file_get_contents($source . '/skeleton/config/local.php.example');
    if ($template === false) {
        fail('Missing skeleton/config/local.php.example in the framework source.');
    }

    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    $content = strtr($template, [
        '__APP_KEY__'        => 'base64:' . base64_encode(random_bytes(32)),
        '__ADMIN_PASSWORD__' => $password,
    ]);

    if (file_put_contents($file, $content) === false) {
        fail("Cannot write '{$file}'.");
    }
    chmod($file, 0640);

    return $password;
}

/**
 * @brief Gives the web server write access to storage/ and read access to local.php.
 *
 * @param string $target Project directory.
 * @param string $webUser Web server user (group with the same name is used).
 * @return void
 */
function applyPermissions(string $target, string $webUser): void
{
    $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    $paths = [$target . '/storage', $target . '/storage/logs', $target . '/storage/cache'];

    foreach ($paths as $path) {
        chmod($path, 02775);
        if ($isRoot) {
            chown($path, $webUser);
            chgrp($path, $webUser);
        }
    }

    if ($isRoot) {
        chgrp($target . '/config/local.php', $webUser);
        return;
    }

    out("  ! Not running as root: make storage/ writable and config/local.php readable for '{$webUser}':");
    out("      sudo chown -R {$webUser}:{$webUser} {$target}/storage && sudo chgrp {$webUser} {$target}/config/local.php");
}

/**
 * @brief Main entry point.
 *
 * @param list<string> $argv Raw arguments.
 * @return void
 */
function main(array $argv): void
{
    $options = parseOptions($argv);
    $source = dirname(__DIR__);
    $update = isset($options['update']);
    $webUser = is_string($options['web-user'] ?? null) ? $options['web-user'] : 'www-data';

    if (!is_string($options['target'] ?? null) || $options['target'] === '') {
        fail('Usage: php bin/install.php --target=<dir> [--update] [--migrate] [--no-example] [--web-user=www-data]');
    }
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/D', $webUser)) {
        fail("Invalid --web-user '{$webUser}'.");
    }

    $target = rtrim($options['target'], '/');
    if ($update && !is_dir($target)) {
        fail("'{$target}' does not exist; run without --update.");
    }
    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        fail("Cannot create target directory '{$target}'.");
    }
    $target = (string)realpath($target);

    if ($target === realpath($source)) {
        fail('The target must differ from the framework source directory.');
    }

    $installed = is_file($target . '/core/bootstrap.php');
    if ($update && !$installed) {
        fail("'{$target}' does not contain a Frasm installation; run without --update.");
    }
    if (!$update && $installed) {
        fail("'{$target}' already contains Frasm; use --update to upgrade the framework files.");
    }
    if (!$update && (glob($target . '/*') ?: []) !== []) {
        fail("'{$target}' is not empty; install into an empty or new directory.");
    }

    out(($update ? 'Updating' : 'Installing') . " Frasm in {$target}");
    checkRequirements();

    // 1. Framework files (always replaced)
    foreach (FRAMEWORK_DIRS as $directory) {
        replaceDirectory("{$source}/{$directory}", "{$target}/{$directory}");
    }
    foreach (FRAMEWORK_FILES as $file) {
        if (is_file("{$source}/{$file}")) {
            replaceFile("{$source}/{$file}", "{$target}/{$file}");
        }
    }

    // Framework scripts: replace current ones, drop ones that no longer exist
    $scripts = array_map('basename', glob("{$source}/public/js/frasm-*.js") ?: []);
    foreach ($scripts as $script) {
        replaceFile("{$source}/public/js/{$script}", "{$target}/public/js/{$script}");
    }
    foreach (glob("{$target}/public/js/frasm-*.js") ?: [] as $existing) {
        if (!in_array(basename($existing), $scripts, true)) {
            unlink($existing);
        }
    }

    // 2. Configuration defaults (never overwritten)
    if (!is_dir("{$target}/config")) {
        mkdir("{$target}/config", 0755);
    }
    foreach (glob("{$source}/config/*.php") ?: [] as $config) {
        $name = basename($config);
        if ($name === 'local.php') {
            continue;
        }
        $destination = "{$target}/config/{$name}";
        if (!is_file($destination)) {
            copy($config, $destination);
            out("  + config/{$name}");
        } elseif (md5_file($config) !== md5_file($destination)) {
            out("  ~ config/{$name} differs from the new default (kept; compare with {$source}/config/{$name})");
        }
    }

    // 3. Project structure (created once)
    $adminPassword = null;
    if (!$update) {
        if (isset($options['no-example'])) {
            foreach (['app/Controllers', 'app/Models', 'app/Views', 'app/Components'] as $directory) {
                mkdir("{$target}/{$directory}", 0755, true);
            }
        } else {
            copyDirectory("{$source}/skeleton/app", "{$target}/app");
        }
        copyDirectory("{$source}/skeleton/database", "{$target}/database");
        copy("{$source}/skeleton/gitignore", "{$target}/.gitignore");
        $adminPassword = createLocalConfig($source, $target);
    }

    foreach (['storage/logs', 'storage/cache'] as $directory) {
        if (!is_dir("{$target}/{$directory}")) {
            mkdir("{$target}/{$directory}", 02775, true);
        }
    }
    touch("{$target}/storage/.gitkeep");

    applyPermissions($target, $webUser);

    // 4. Version stamp
    file_put_contents("{$target}/.frasm-version", json_encode([
        'ref'          => is_string($options['ref'] ?? null) ? $options['ref'] : null,
        'commit'       => is_string($options['commit'] ?? null) ? $options['commit'] : null,
        'installed_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    // 5. Post-update tasks
    if ($update) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$target}/bin/routes.php") . ' clear');
        if (isset($options['migrate'])) {
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$target}/bin/db.php") . ' migrate', $status);
            if ($status !== 0) {
                fail('Database migration failed.');
            }
        }

        out('✔ Framework updated.');
        out('  Next: reload the web server (OPcache) and restart the queue worker if it runs as a service:');
        out('      sudo systemctl reload apache2; sudo systemctl restart frasm-queue');
        if (!isset($options['migrate'])) {
            out("      php {$target}/bin/db.php migrate   # apply core schema / app migrations");
        }
        return;
    }

    out('✔ Frasm installed.');
    out('');
    out('Next steps:');
    out("  1. Set database credentials in {$target}/config/local.php");
    out("  2. php {$target}/bin/db.php migrate && php {$target}/bin/seed-admin.php");
    if ($adminPassword !== null) {
        out("     Admin login: admin / {$adminPassword}   (stored in config/local.php, change it after the first login)");
    }
    out("  3. Point the web server DocumentRoot to {$target}/public (Apache: AllowOverride All, mod_rewrite)");
    out("     Quick local preview: cd {$target} && make serve");
}

main($argv);
