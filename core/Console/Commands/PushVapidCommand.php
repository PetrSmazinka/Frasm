<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Push\Vapid;

/**
 * @file PushVapidCommand.php
 * @brief Generates a VAPID key pair for Web Push.
 */
final class PushVapidCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'push:vapid';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Generate VAPID keys for Web Push';
    }

    /**
     * @brief Prints new keys as a config snippet.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $keys = Vapid::generateKeys();

        $output->line("'push' => [");
        $output->line("    'enabled' => true,");
        $output->line("    'vapid'   => [");
        $output->line("        'subject'     => 'mailto:you@example.com',");
        $output->line("        'public_key'  => '{$keys['public_key']}',");
        $output->line("        'private_key' => '{$keys['private_key']}',");
        $output->line('    ],');
        $output->line('],');
        $output->line();
        $output->comment('Add this to config/local.php (keep the private key secret).');
        $output->comment('Changing the keys later invalidates all existing browser subscriptions.');

        return 0;
    }
}
