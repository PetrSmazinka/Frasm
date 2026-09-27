<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Logger\Logger;

/**
 * @file LogsArchiveCommand.php
 * @brief Moves logs from a volatile directory (tmpfs) to persistent storage.
 */
final class LogsArchiveCommand extends Command
{
    /**
     * @brief LogsArchiveCommand constructor.
     *
     * @param Logger $logger Application logger.
     */
    public function __construct(private readonly Logger $logger)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'logs:archive';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Move logs from logging.path (tmpfs) to logging.archive_path';
    }

    /**
     * @brief Archives the logs.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (Config::get('logging.archive_path') === null) {
            $output->success('Nothing to do: logging.archive_path is not configured');
            return 0;
        }

        $output->success('Archived ' . $this->logger->archive() . ' bytes of logs');
        return 0;
    }
}
