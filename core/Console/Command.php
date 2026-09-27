<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * @file Command.php
 * @brief Base class of console commands executed by bin/frasm.
 */

/**
 * @class Command
 * @brief Declares a command's name, description, arguments and options, and implements handle().
 *
 * Commands are instantiated through the DI container, so dependencies can be declared in the
 * constructor. Application commands placed in app/Commands/*Command.php are discovered automatically.
 */
abstract class Command
{
    /**
     * @brief Returns the command name, e.g. 'db:migrate'.
     *
     * @return string
     */
    abstract public function name(): string;

    /**
     * @brief Returns a one-line description shown in the command list.
     *
     * @return string
     */
    abstract public function description(): string;

    /**
     * @brief Executes the command.
     *
     * @param Input $input Parsed arguments and options.
     * @param Output $output Console output.
     * @return int Exit code (0 = success).
     */
    abstract public function handle(Input $input, Output $output): int;

    /**
     * @brief Declares positional arguments.
     *
     * @return array<string, string> Name (suffix '?' = optional) => description.
     */
    public function arguments(): array
    {
        return [];
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string> Name (suffix '=' = takes a value) => description.
     */
    public function options(): array
    {
        return [];
    }

    /**
     * @brief Returns additional help text (examples, notes).
     *
     * @return string
     */
    public function help(): string
    {
        return '';
    }

    /**
     * @brief Asks for confirmation of a destructive action; `--force` skips the question.
     *
     * Non-interactive sessions (cron, CI) must pass --force explicitly.
     *
     * @param Input $input Parsed input (must declare the 'force' option).
     * @param Output $output Console output.
     * @param string $question Question text.
     * @return bool
     */
    protected function confirmed(Input $input, Output $output, string $question): bool
    {
        if ($input->flag('force')) {
            return true;
        }

        if (!$output->confirm($question)) {
            $output->warning('Aborted. Pass --force to run without confirmation.');
            return false;
        }

        return true;
    }
}
