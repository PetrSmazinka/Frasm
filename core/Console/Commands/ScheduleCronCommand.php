<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Scheduling\CronTab;
use Core\Scheduling\Scheduler;

/**
 * @file ScheduleCronCommand.php
 * @brief Adds or removes the scheduler's cron entry according to `scheduler.enabled` and `scheduler.runner`.
 */

/**
 * @class ScheduleCronCommand
 * @brief Keeps the crontab in sync with the configuration; run by the installer on install and update.
 *
 * The entry lives in the crontab of the current user, or of --user (root only). It is a marked
 * block identified by the project path, so other crontab lines and other projects are untouched.
 * With `scheduler.runner = daemon` the runs are started by `schedule:work` instead, so the entry is
 * removed; where no crontab exists (e.g. inside a container) there is then nothing to do.
 */
final class ScheduleCronCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'schedule:cron';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Install or remove the scheduler cron entry according to scheduler.enabled and scheduler.runner';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'user='  => 'Crontab owner (requires root; default: current user)',
            'remove' => 'Remove the entry even if the scheduler is enabled',
        ];
    }

    /**
     * @brief Synchronizes the crontab.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $user = $input->option('user');
        if ($user !== null && !preg_match('/^[a-z_][a-z0-9_-]*$/D', $user)) {
            $output->error("Invalid user '{$user}'.");
            return 1;
        }

        $daemon = Scheduler::runner() === Scheduler::RUNNER_DAEMON;
        $enabled = Scheduler::isEnabled() && !$daemon && !$input->flag('remove');

        if (!CronTab::isAvailable()) {
            if ($enabled) {
                $output->error('The crontab command is not installed (Debian: sudo apt install cron;'
                    . ' in a container set scheduler.runner = daemon and run schedule:work).');
                return 1;
            }
            // Without crontab there can be no entry to remove
            $output->success($this->noEntryMessage($daemon));
            return 0;
        }

        $crontab = new CronTab($user);
        $body = $enabled ? $this->entry() : null;

        $result = $crontab->syncBlock(FRASM_ROOT_DIR, $body);
        $owner = $crontab->owner();

        match ($result) {
            'installed' => $output->success("Scheduler cron entry installed (crontab of {$owner})"),
            'updated'   => $output->success("Scheduler cron entry updated (crontab of {$owner})"),
            'removed'   => $output->success("Scheduler cron entry removed (crontab of {$owner})"),
            default     => $output->success($enabled
                ? "Scheduler cron entry is up to date (crontab of {$owner})"
                : $this->noEntryMessage($daemon)),
        };

        return 0;
    }

    /**
     * @brief Explains why no cron entry is installed.
     *
     * @param bool $daemon Whether `scheduler.runner` is 'daemon'.
     * @return string
     */
    private function noEntryMessage(bool $daemon): string
    {
        if (!Scheduler::isEnabled()) {
            return 'Scheduler is disabled, no cron entry';
        }

        return $daemon
            ? 'Scheduler runs as a daemon (scheduler.runner = daemon), no cron entry: keep `php bin/frasm schedule:work` running'
            : 'Scheduler cron entry is not installed (--remove)';
    }

    /**
     * @brief Builds the cron line running the scheduler every minute.
     *
     * @return string
     */
    private function entry(): string
    {
        // The generic `php` survives PHP upgrades better than the resolved binary (e.g. /usr/bin/php8.5)
        $php = (string)(Config::get('scheduler.php_binary')
            ?? (trim((string)shell_exec('command -v php 2>/dev/null')) ?: PHP_BINARY));

        // '%' means "newline" in a cron command and must be escaped
        $quote = static fn(string $value): string => str_replace('%', '\%', escapeshellarg($value));

        return '* * * * * cd ' . $quote(FRASM_ROOT_DIR) . ' && ' . $quote($php) . ' bin/frasm schedule:run >/dev/null 2>&1';
    }
}
