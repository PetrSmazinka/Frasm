<?php

declare(strict_types=1);

namespace Core\Scheduling;

use Core\Exceptions\CoreException;

/**
 * @file CronTab.php
 * @brief Reads and rewrites a user's crontab through the `crontab` command.
 */

/**
 * @class CronTab
 * @brief Maintains marker-delimited blocks in a crontab without touching the rest of it.
 *
 * A managed block looks like:
 *   # >>> frasm scheduler: /var/www/app (managed by bin/frasm, do not edit)
 *   * * * * * cd '/var/www/app' && '/usr/bin/php' bin/frasm schedule:run >/dev/null 2>&1
 *   # <<< frasm scheduler: /var/www/app
 * so several projects on one server keep separate entries.
 */
final class CronTab
{
    /**
     * @brief CronTab constructor.
     *
     * @param string|null $user Crontab owner (null = current user; another user requires root).
     */
    public function __construct(private readonly ?string $user = null)
    {
    }

    /**
     * @brief Checks whether the crontab command is available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return trim((string)shell_exec('command -v crontab 2>/dev/null')) !== '';
    }

    /**
     * @brief Returns the crontab owner as seen by the system.
     *
     * @return string
     */
    public function owner(): string
    {
        return $this->user ?? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown');
    }

    /**
     * @brief Inserts, replaces or (with a null body) removes a managed block.
     *
     * @param string $id Block identifier (e.g. the project path).
     * @param string|null $body Block content (cron lines), or null to remove the block.
     * @return string 'installed', 'updated', 'removed' or 'unchanged'.
     * @throws CoreException When the crontab cannot be read or written.
     */
    public function syncBlock(string $id, ?string $body): string
    {
        if (preg_match('/[\r\n]/', $id)) {
            throw new CoreException('Invalid crontab block id.');
        }

        $begin = "# >>> frasm scheduler: {$id} (managed by bin/frasm, do not edit)";
        $end = "# <<< frasm scheduler: {$id}";

        $current = $this->read();
        $pattern = '/^' . preg_quote("# >>> frasm scheduler: {$id} ", '/') . '.*?^' . preg_quote($end, '/') . '\R?/ms';
        $hadBlock = preg_match($pattern, $current) === 1;
        $without = rtrim((string)preg_replace($pattern, '', $current));

        $new = $without;
        if ($body !== null) {
            $new = ($without === '' ? '' : $without . "\n\n") . $begin . "\n" . trim($body) . "\n" . $end;
        }
        $new = $new === '' ? '' : $new . "\n";

        if ($new === ($current === '' ? '' : rtrim($current) . "\n")) {
            return 'unchanged';
        }

        $this->write($new);

        return $body === null ? 'removed' : ($hadBlock ? 'updated' : 'installed');
    }

    /**
     * @brief Reads the crontab (empty string when the user has none).
     *
     * @return string
     * @throws CoreException When crontab fails for another reason.
     */
    public function read(): string
    {
        [$status, $output, $error] = $this->run(['-l'], null);

        if ($status !== 0) {
            if (stripos($error, 'no crontab') !== false) {
                return '';
            }
            throw new CoreException('Cannot read the crontab of ' . $this->owner() . ': ' . trim($error));
        }

        return $output;
    }

    /**
     * @brief Replaces the whole crontab.
     *
     * @param string $content New crontab content.
     * @return void
     * @throws CoreException When crontab rejects the content.
     */
    private function write(string $content): void
    {
        [$status, , $error] = $this->run(['-'], $content);

        if ($status !== 0) {
            throw new CoreException('Cannot write the crontab of ' . $this->owner() . ': ' . trim($error));
        }
    }

    /**
     * @brief Executes the crontab command without a shell.
     *
     * @param list<string> $arguments Arguments after the optional -u.
     * @param string|null $input Standard input.
     * @return array{0: int, 1: string, 2: string} Exit status, stdout, stderr.
     * @throws CoreException When the process cannot be started.
     */
    private function run(array $arguments, ?string $input): array
    {
        $command = array_merge(['crontab'], $this->user !== null ? ['-u', $this->user] : [], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new CoreException('Cannot run the crontab command.');
        }

        fwrite($pipes[0], $input ?? '');
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]);
        $error = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
