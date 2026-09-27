<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;

/**
 * @file ServeCommand.php
 * @brief Starts PHP's built-in development server.
 */
final class ServeCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'serve';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Start the development server (never use it in production)';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'host=' => 'Interface to listen on (default 127.0.0.1)',
            'port=' => 'Port (default 8000)',
        ];
    }

    /**
     * @brief Runs the server in the foreground.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $host = $input->option('host', '127.0.0.1');
        $port = $input->intOption('port', 8000);

        if (!preg_match('/^[A-Za-z0-9.:\[\]-]+$/D', $host) || $port < 1 || $port > 65535) {
            $output->error('Invalid host or port.');
            return 1;
        }

        $output->success("Development server running at http://{$host}:{$port} (Ctrl+C to stop)");

        $command = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("{$host}:{$port}")
            . ' -t ' . escapeshellarg(FRASM_ROOT_DIR . '/public')
            . ' ' . escapeshellarg(FRASM_CORE_DIR . '/server.php');
        passthru($command, $status);

        return $status;
    }
}
