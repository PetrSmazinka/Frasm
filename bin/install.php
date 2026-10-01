<?php

declare(strict_types=1);

/**
 * @file install.php
 * @brief Creates a new Frasm project or updates the framework files of an existing one.
 *
 * Runs from a downloaded copy of the framework (see install.sh), never from the target itself.
 *
 * Usage:
 *   php bin/install.php --target=/var/www/app [--no-example] [--pwa] [--scheduler] [--web-user=www-data]
 *   php bin/install.php --target=/var/www/app --update [--migrate] [--web-user=www-data]
 *   php bin/install.php --target=/var/www/app --restore [--migrate] [--web-user=www-data]
 *   (--web-user names the web server group that gets access to storage/ and config/local.php)
 *   Optional: --ref=<branch|tag> --commit=<sha> (recorded in .frasm-version)
 *   Docker (set by install.sh --docker): --host-target=<dir> is the target as seen on the host, where
 *   the printed next steps are run with make (frasm.mk forwards them into the container)
 *
 * --restore reinstalls the framework into a project cloned from its own repository, which holds only
 * the application (the framework files are git-ignored, see gitignoreBlock()).
 *
 * Ownership rules:
 *   - framework files (FRAMEWORK_DIRS, FRAMEWORK_FILES, public/js/frasm-*.js) are always replaced;
 *   - config/*.php defaults are copied only when missing (never overwritten);
 *   - app/, database/, storage/ and config/local.php are created once and never touched again;
 *   - .gitignore is created once; only its block between GITIGNORE_BEGIN and GITIGNORE_END is rewritten.
 */

use Core\Console\Output;

require_once dirname(__DIR__) . '/core/Console/Output.php';

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
    'LICENSE',
];

/**
 * @var string First line of the installer-managed block in .gitignore.
 */
const GITIGNORE_BEGIN = '# >>> frasm (managed by the installer and rewritten on update; add your own rules outside this block)';

/**
 * @var string Last line of the installer-managed block in .gitignore.
 */
const GITIGNORE_END = '# <<< frasm';

/**
 * @var list<string> Required PHP extensions.
 */
const REQUIRED_EXTENSIONS = ['mysqli', 'openssl', 'mbstring', 'json', 'posix'];

/**
 * @var array<string, string> Optional PHP extensions and the feature needing them.
 */
const OPTIONAL_EXTENSIONS = ['curl' => 'Web Push delivery', 'pcntl' => 'graceful queue worker shutdown', 'Zend OPcache' => 'performance'];

/**
 * @brief Returns the shared console output (same style as bin/frasm).
 *
 * @return Output
 */
function console(): Output
{
    static $output = null;
    return $output ??= new Output();
}

/**
 * @brief Prints an error and terminates.
 *
 * @param string $message Error message.
 * @return never
 */
function fail(string $message): never
{
    console()->error($message);
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
            console()->warning("Optional PHP extension '{$extension}' is missing ({$feature})");
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
 * @param bool $pwa Enable the installable web app.
 * @param bool $scheduler Enable the scheduler (cron entry).
 * @return string|null Generated admin password, or null when local.php already existed.
 */
function createLocalConfig(string $source, string $target, bool $pwa = false, bool $scheduler = false): ?string
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
        '__PWA_ENABLED__'    => $pwa ? 'true' : 'false',
        '__SCHEDULER_ENABLED__' => $scheduler ? 'true' : 'false',
    ]);

    if (file_put_contents($file, $content) === false) {
        fail("Cannot write '{$file}'.");
    }
    chmod($file, 0640);

    return $password;
}

/**
 * @brief Builds the installer-managed .gitignore block: everything the installer can recreate, plus secrets.
 *
 * A project repository then holds only the application; `install.sh --restore` brings the rest back.
 *
 * @return string Block including the marker lines and a trailing newline.
 */
function gitignoreBlock(): string
{
    $lines = [
        GITIGNORE_BEGIN,
        '# Framework files (after cloning the project: install.sh --restore .)',
        ...array_map(fn(string $directory): string => "/{$directory}/", FRAMEWORK_DIRS),
        ...array_map(fn(string $file): string => "/{$file}", FRAMEWORK_FILES),
        '/public/js/frasm-*.js',
        '# Generated from config/pwa.php by `php bin/frasm pwa:build`',
        '/public/manifest.webmanifest',
        '/public/pwa/',
        '# Secrets, machine settings and runtime data (back up config/local.php separately: it holds the application key)',
        '/config/local.php',
        '/frasm.mk',
        '/storage/*',
        '!/storage/.gitkeep',
        '# Leftovers of an interrupted install',
        '*.frasm-new',
        '*.frasm-old',
        GITIGNORE_END,
    ];

    return implode("\n", $lines) . "\n";
}

/**
 * @brief Writes the managed block into .gitignore: replaces an existing block, otherwise prepends it
 *        (rules below it, i.e. the project's own, take precedence).
 *
 * @param string $file Path of .gitignore.
 * @return bool True when the file changed.
 */
function updateGitignore(string $file): bool
{
    $current = is_file($file) ? (string)file_get_contents($file) : '';
    $block = gitignoreBlock();
    // Matched by the marker prefix only, so a block written with a differently worded first line is replaced too
    $pattern = '/^# >>> frasm\b.*?^' . preg_quote(GITIGNORE_END, '/') . '[^\n]*(?:\n|$)/ms';

    $updated = preg_match($pattern, $current)
        ? (string)preg_replace_callback($pattern, fn(): string => $block, $current, 1)
        : $block . ($current !== '' ? "\n" . $current : '');

    if ($updated === $current) {
        return false;
    }
    if (file_put_contents($file, $updated) === false) {
        fail("Cannot write '{$file}'.");
    }

    return true;
}

/**
 * @brief Recursively changes the owner and group of a directory tree.
 *
 * @param string $path Directory.
 * @param string $user Owner.
 * @param int $group Group id.
 * @return void
 */
function chownRecursive(string $path, string $user, int $group): void
{
    @chown($path, $user);
    @chgrp($path, $group);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        @lchown($item->getPathname(), $user);
        @lchgrp($item->getPathname(), $group);
    }
}

/**
 * @brief Gives a path the web server group and the required mode without degrading it on failure.
 *
 * @param string $path File or directory.
 * @param string $group Group name.
 * @param int $gid Group id.
 * @param int $mode Target mode (including setgid for directories).
 * @return bool True when the path ends up with the group and mode.
 */
function setGroupAndMode(string $path, string $group, int $gid, int $mode): bool
{
    clearstatcache(true, $path);
    if (filegroup($path) !== $gid && !@chgrp($path, $group)) {
        return false;
    }

    clearstatcache(true, $path);
    if ((fileperms($path) & 07777) === $mode) {
        return true;
    }

    @chmod($path, $mode);
    clearstatcache(true, $path);

    return (fileperms($path) & 07777) === $mode;
}

/**
 * @brief Sets the ownership model of a project.
 *
 * - Every file belongs to the deploying user, so later updates need no sudo. When the installer runs
 *   through `sudo`, the project is handed over to the invoking user (SUDO_USER).
 * - The web server group gets read access to config/local.php and write access to storage/
 *   (setgid directories, so files created later by the web server or the CLI keep the group).
 *
 * Changing a file's group works as root, or as the file's owner when they are a member of the group.
 *
 * @param string $target Project directory.
 * @param string $webGroup Web server group.
 * @return array{0: string, 1: list<string>}|null Step still required (description, commands), or null when done.
 */
function applyPermissions(string $target, string $webGroup): ?array
{
    $groupInfo = posix_getgrnam($webGroup);
    if ($groupInfo === false) {
        return ["The group '{$webGroup}' does not exist; rerun with --web-user=<web server group>.", []];
    }

    $isRoot = posix_geteuid() === 0;
    $sudoUser = getenv('SUDO_USER');
    if ($isRoot && is_string($sudoUser) && $sudoUser !== '' && $sudoUser !== 'root') {
        $owner = posix_getpwnam($sudoUser);
        if ($owner !== false) {
            chownRecursive($target, $sudoUser, (int)$owner['gid']);
        }
    }

    $storage = $target . '/storage';
    $localConfig = $target . '/config/local.php';
    $paths = [$storage];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $paths[] = $item->getPathname();
    }

    // The group is changed first: chmod() by a user who is not an active member of the file's group
    // silently drops the setgid bit, so permissions are only set once the group is in place.
    $ok = true;
    foreach ($paths as $path) {
        $ok = setGroupAndMode($path, $webGroup, (int)$groupInfo['gid'], is_dir($path) ? 02775 : 0664) && $ok;
    }
    if (is_file($localConfig)) {
        $ok = setGroupAndMode($localConfig, $webGroup, (int)$groupInfo['gid'], 0640) && $ok;
    }

    clearstatcache();
    if ($ok) {
        return null;
    }

    $user = posix_getpwuid(posix_geteuid())['name'] ?? 'USER';
    $commands = "chgrp -R {$webGroup} {$storage} {$localConfig} && chmod -R g+rwX {$storage}";
    $isMember = in_array($user, $groupInfo['members'], true);
    $isActive = in_array($groupInfo['gid'], posix_getgroups() ?: [], true);

    if ($isMember && !$isActive) {
        return [
            "Your membership in '{$webGroup}' is not active in this session yet. Log out and back in and rerun the installer, or run:",
            ["sg {$webGroup} -c \"{$commands}\""],
        ];
    }

    return [
        "Grant the web server access (required):",
        [
            "sudo sh -c \"{$commands}\"",
            "# tip: `sudo usermod -aG {$webGroup} {$user}` (then log in again) lets the installer do this itself",
        ],
    ];
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
    $restore = isset($options['restore']);
    $update = isset($options['update']) || $restore;
    $webUser = is_string($options['web-user'] ?? null) ? $options['web-user'] : 'www-data';

    if (!is_string($options['target'] ?? null) || $options['target'] === '') {
        fail('Usage: php bin/install.php --target=<dir> [--update|--restore] [--migrate] [--no-example] [--web-user=www-data]');
    }
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/D', $webUser)) {
        fail("Invalid --web-user '{$webUser}'.");
    }

    $target = rtrim($options['target'], '/');
    if ($update && !is_dir($target)) {
        fail("'{$target}' does not exist; run without --update" . ($restore ? ' / --restore, or clone the project first.' : '.'));
    }
    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
        $user = posix_getpwuid(posix_geteuid())['name'] ?? 'USER';
        fail("Cannot create '{$target}' (no write permission in " . dirname($target) . ").\n"
            . "  Create it once and give it to yourself, then run the installer again without sudo:\n"
            . "      sudo install -d -o {$user} -g {$webUser} {$target}\n"
            . "  or run the whole installer with sudo (the project is then handed over to you).");
    }
    $target = (string)realpath($target);

    $hostTarget = $options['host-target'] ?? null;
    if ($hostTarget !== null && (!is_string($hostTarget) || !str_starts_with($hostTarget, '/'))) {
        fail('--host-target must be an absolute path.');
    }
    $hostTarget = $hostTarget === null ? null : rtrim($hostTarget, '/');

    if ($target === realpath($source)) {
        fail('The target must differ from the framework source directory.');
    }

    $installed = is_file($target . '/core/bootstrap.php');
    if ($restore) {
        if ($installed) {
            fail("'{$target}' already contains the framework; use --update to upgrade it.");
        }
        if (!is_dir($target . '/app') && !is_file($target . '/.frasm-version')) {
            fail("'{$target}' is not a Frasm project (neither app/ nor .frasm-version found).");
        }
    } elseif ($update && !$installed) {
        fail("'{$target}' does not contain a Frasm installation; run without --update"
            . (is_dir($target . '/app') ? ', or with --restore for a project cloned from its repository.' : '.'));
    }
    if (!$update && $installed) {
        fail("'{$target}' already contains Frasm; use --update to upgrade the framework files.");
    }
    if (!$update && (glob($target . '/*') ?: []) !== []) {
        fail("'{$target}' is not empty; install into an empty or new directory.");
    }

    console()->title(($restore ? 'Restoring' : ($update ? 'Updating' : 'Installing')) . " Frasm in {$target}");
    checkRequirements();
    console()->success('Requirements met (PHP ' . PHP_VERSION . ')');

    // 1. Framework files (always replaced)
    foreach (FRAMEWORK_DIRS as $directory) {
        replaceDirectory("{$source}/{$directory}", "{$target}/{$directory}");
    }
    foreach (FRAMEWORK_FILES as $file) {
        if (is_file("{$source}/{$file}")) {
            replaceFile("{$source}/{$file}", "{$target}/{$file}");
        }
    }

    console()->success('Framework files ' . ($update && !$restore ? 'updated' : 'installed'));

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
            if ($update) {
                console()->success("Added new configuration file config/{$name}");
            }
        } elseif (md5_file($config) !== md5_file($destination)) {
            console()->warning("config/{$name} differs from the new default (your version is kept)");
        }
    }

    // 3. Project structure (created once)
    $adminPassword = null;
    if (!$update) {
        if (isset($options['no-example'])) {
            foreach (['app/Controllers', 'app/Models', 'app/Views', 'app/Components', 'app/Assets/css', 'app/Assets/js', 'app/Tests'] as $directory) {
                mkdir("{$target}/{$directory}", 0755, true);
            }
        } else {
            copyDirectory("{$source}/skeleton/app", "{$target}/app");
        }
        copyDirectory("{$source}/skeleton/database", "{$target}/database");
        copy("{$source}/skeleton/gitignore", "{$target}/.gitignore");
        $adminPassword = createLocalConfig($source, $target, isset($options['pwa']), isset($options['scheduler']));
        console()->success('Project structure created' . (isset($options['no-example']) ? '' : ' with the Hello world application'));
        console()->success('config/local.php created with a new app key');
    }

    if ($restore) {
        $adminPassword = createLocalConfig($source, $target, isset($options['pwa']), isset($options['scheduler']));
        if ($adminPassword !== null) {
            console()->warning('config/local.php was missing and has been created with a NEW application key:'
                . ' put your backed-up config/local.php in its place (it also holds the database credentials),'
                . ' otherwise everything signed with the original key (remember-me cookies, tokens) becomes invalid');
        }
    }

    // A repository does not keep empty directories
    if (!is_dir("{$target}/database/migrations")) {
        mkdir("{$target}/database/migrations", 0755, true);
    }

    if (updateGitignore("{$target}/.gitignore") && $update) {
        console()->success('.gitignore: framework rules updated');
    }

    foreach (['storage/logs', 'storage/cache'] as $directory) {
        if (!is_dir("{$target}/{$directory}")) {
            mkdir("{$target}/{$directory}", 02775, true);
        }
    }
    touch("{$target}/storage/.gitkeep");

    $permissionStep = applyPermissions($target, $webUser);
    if ($permissionStep === null) {
        console()->success("Permissions set (owner " . (posix_getpwuid(fileowner($target))['name'] ?? '?') . ", web server group '{$webUser}')");
    }

    // 4. Version stamp (versioned with the project: rewritten only when the version changes)
    $version = [
        'ref'    => is_string($options['ref'] ?? null) ? $options['ref'] : null,
        'commit' => is_string($options['commit'] ?? null) ? $options['commit'] : null,
    ];
    $stamp = json_decode((string)@file_get_contents("{$target}/.frasm-version"), true);
    if (!is_array($stamp) || ($stamp['ref'] ?? null) !== $version['ref'] || ($stamp['commit'] ?? null) !== $version['commit']) {
        file_put_contents("{$target}/.frasm-version", json_encode(
            $version + ['installed_at' => date('c')],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL);
    }

    $frasm = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$target}/bin/frasm");
    $steps = [];

    // Next steps run on the host (Docker: through make and frasm.mk); the web server sees $target
    $hostDir = $hostTarget ?? $target;

    // Suggest the Makefile shortcuts only when make is installed; in Docker only make reaches PHP from the host
    $hasMake = $hostTarget !== null || trim((string)shell_exec('command -v make 2>/dev/null')) !== '';
    $task = static fn(string $shortcut, string $command): string => $hasMake ? "make {$shortcut}" : "php bin/frasm {$command}";

    if ($permissionStep !== null) {
        console()->warning("The web server group '{$webUser}' cannot read config/local.php or write storage/ yet");
        // Its commands run on the host
        $steps[] = [$permissionStep[0], array_map(static fn(string $command): string => strtr($command, [$target => $hostDir]), $permissionStep[1])];
    }

    // 5. Installable web app: generate the manifest and icons when pwa.enabled (needs GD for the icons).
    //    Driven by the configuration, not by existing files: they are git-ignored and missing after --restore.
    passthru("{$frasm} pwa:build --if-enabled", $pwaStatus);
    if ($pwaStatus !== 0) {
        $steps[] = ['Generate the web app manifest and icons (needs the PHP GD extension):', [
            "cd {$hostDir} && " . $task('pwa:build', 'pwa:build'),
        ]];
    }

    // 6. Scheduler: add or remove the cron entry according to scheduler.enabled and scheduler.runner
    //    (root installs manage the web server user's crontab, others their own; runner 'daemon' needs no crontab)
    $cronUser = posix_geteuid() === 0 ? ' --user=' . escapeshellarg($webUser) : '';
    passthru("{$frasm} schedule:cron{$cronUser}", $cronStatus);
    if ($cronStatus !== 0) {
        $steps[] = ['Install the scheduler cron entry:', ["cd {$hostDir} && " . $task('schedule:cron', 'schedule:cron')]];
    }

    // 7. Post-update tasks
    if ($update) {
        passthru("{$frasm} route:clear");
        if ($restore && $adminPassword !== null) {
            // A fresh local.php has no database credentials yet, nor the PWA and scheduler settings
            $steps[] = ["Restore your backed-up {$hostDir}/config/local.php (or enter the database credentials), then:", [
                "cd {$hostDir}",
                $task('migrate', 'db:migrate'),
                $task('pwa:build ARGS=--if-enabled', 'pwa:build --if-enabled') . ' && ' . $task('schedule:cron', 'schedule:cron'),
            ]];
        } elseif (isset($options['migrate'])) {
            passthru("{$frasm} db:migrate", $status);
            if ($status !== 0) {
                fail('Database migration failed.');
            }
        } else {
            $steps[] = ['Apply database changes:', ["cd {$hostDir} && " . $task('migrate', 'db:migrate')]];
        }
        $steps[] = $hostTarget === null
            ? ['Reload PHP (OPcache) and restart the queue worker if it runs as a service:', [
                'sudo systemctl reload apache2 && sudo systemctl restart frasm-queue',
            ]]
            : ['Restart the PHP containers that keep code in memory (OPcache without revalidation, a queue worker):', [
                'docker compose restart <service>',
            ]];

        console()->line();
        console()->success($restore ? 'Frasm restored' : 'Frasm updated');
        printSteps($steps);
        return;
    }

    $steps[] = ["Set the database credentials in {$hostDir}/config/local.php, then:", [
        "cd {$hostDir}",
        $task('migrate', 'db:migrate'),
        $task('seed', 'db:seed'),
    ]];
    // In Docker the development server would listen inside the container, unreachable from the host
    $steps[] = $hostTarget === null
        ? ["Point the web server's DocumentRoot to {$target}/public (Apache: AllowOverride All, mod_rewrite),", [
            'or preview locally: ' . $task('serve', 'serve'),
        ]]
        : ["Point the web server's DocumentRoot to {$target}/public (Apache: AllowOverride All, mod_rewrite).", []];

    console()->line();
    console()->success('Frasm installed');
    if ($adminPassword !== null) {
        console()->line("  Administrator: admin / {$adminPassword}  (stored in config/local.php; change it after signing in)");
    }
    printSteps($steps);
}

/**
 * @brief Prints numbered next steps with their shell commands.
 *
 * @param list<array{0: string, 1: list<string>}> $steps Step description and commands.
 * @return void
 */
function printSteps(array $steps): void
{
    if ($steps === []) {
        return;
    }

    console()->line();
    console()->title('Next steps');
    foreach ($steps as $index => [$description, $commands]) {
        console()->line('  ' . ($index + 1) . '. ' . $description);
        foreach ($commands as $command) {
            str_starts_with($command, '#') ? console()->comment('       ' . $command) : console()->line('       ' . $command);
        }
    }
}

main($argv);
