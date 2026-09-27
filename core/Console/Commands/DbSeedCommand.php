<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\DB;

/**
 * @file DbSeedCommand.php
 * @brief Creates or synchronizes the default administrator account.
 */
final class DbSeedCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'db:seed';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Create or update the administrator from auth.default_admin';
    }

    /**
     * @brief Upserts the administrator account.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (!(bool)Config::get('auth.enabled', true)) {
            $output->warning('The Auth module is disabled (auth.enabled = false); nothing to seed.');
            return 0;
        }

        /** @var array{name?: string, username?: string, email?: string, password?: string, permissions?: string} $admin */
        $admin = (array)Config::get('auth.default_admin', []);
        if (empty($admin['username']) || empty($admin['email']) || empty($admin['password'])) {
            $output->error('auth.default_admin needs username, email and password (set the password in config/local.php).');
            return 1;
        }

        $db = DB::getInstance();
        $existing = $db->selectOne(
            'SELECT `id` FROM `frasm_users` WHERE `username` = ? OR `email` = ? LIMIT 1',
            [$admin['username'], $admin['email']]
        );

        $fields = [
            'name'          => (string)($admin['name'] ?? 'Administrator'),
            'password_hash' => password_hash((string)$admin['password'], PASSWORD_DEFAULT),
            'permissions'   => (string)($admin['permissions'] ?? ''),
        ];

        if ($existing !== null) {
            $db->update('frasm_users', $fields, '`id` = ?', [$existing['id']]);
            $output->success("Administrator '{$admin['username']}' updated");
        } else {
            $db->insert('frasm_users', $fields + ['email' => (string)$admin['email'], 'username' => (string)$admin['username']]);
            $output->success("Administrator '{$admin['username']}' created");
        }

        return 0;
    }
}
