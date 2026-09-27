<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Security\Signer;

/**
 * @file KeyGenerateCommand.php
 * @brief Generates a new application key.
 */
final class KeyGenerateCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'key:generate';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Generate a new app.key (HMAC secret) for config/local.php';
    }

    /**
     * @brief Prints a new key.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $output->line(Signer::generateKey());
        $output->comment("Put it into config/local.php as ['app' => ['key' => '...']].");
        $output->comment('Changing the key invalidates rendered live components and pending push action tokens.');

        return 0;
    }
}
