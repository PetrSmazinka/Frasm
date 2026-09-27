<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\DB;

/**
 * @file TokenCreateCommand.php
 * @brief Issues a Bearer API token for an external service.
 */
final class TokenCreateCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'token:create';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Create an API token for an external service (shown only once)';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['service' => 'Service name, e.g. home-assistant'];
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'roles=' => 'Comma-separated roles (default: api.service)',
            'days='  => 'Expire after this many days (default: never)',
        ];
    }

    /**
     * @brief Creates the token.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $service = (string)$input->argument('service');
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $service)) {
            $output->error('The service name may contain letters, digits, dots, dashes and underscores (max. 100).');
            return 1;
        }

        $days = $input->option('days') === null ? null : $input->intOption('days', 0);
        if ($days !== null && $days < 1) {
            $output->error('--days must be a positive number.');
            return 1;
        }

        $token = bin2hex(random_bytes(32));
        DB::getInstance()->query(
            'INSERT INTO `frasm_api_tokens` (`service_name`, `token_hash`, `roles`, `expires_at`)
             VALUES (?, ?, ?, IF(? IS NULL, NULL, NOW() + INTERVAL ? DAY))',
            [$service, hash('sha256', $token), $input->option('roles', 'api.service'), $days, $days]
        );

        $output->success("API token created for '{$service}'");
        $output->line($token);
        $output->comment('Store it securely: only its hash is kept, it cannot be shown again.');

        return 0;
    }
}
