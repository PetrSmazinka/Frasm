<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Console\PasswordPrompt;
use Core\DB\DB;

/**
 * @file UserCreateCommand.php
 * @brief Creates a user account.
 */

/**
 * @class UserCreateCommand
 * @brief Inserts a user into `frasm_users` with the given roles; the password is asked without echo,
 *        read from piped STDIN, or generated with --generate.
 */
final class UserCreateCommand extends Command
{
    use PasswordPrompt;

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'user:create';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Create a user account';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['username' => 'Login name'];
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'email='   => 'Email address (required, also accepted as login)',
            'name='    => 'Display name (default: the username)',
            'roles='   => 'Comma-separated roles, e.g. "editor,reports.view"',
            'generate' => 'Generate a random password and print it',
        ];
    }

    /**
     * @brief Returns usage examples.
     *
     * @return string
     */
    public function help(): string
    {
        return "Examples:\n"
            . "  php bin/frasm user:create jana --email=jana@example.com --name=\"Jana Nová\" --roles=editor\n"
            . "  php bin/frasm user:create kiosk --email=kiosk@example.com --roles=dashboard.view --generate";
    }

    /**
     * @brief Creates the user.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (!(bool)Config::get('auth.enabled', true)) {
            $output->error('The Auth module is disabled (auth.enabled = false).');
            return 1;
        }

        $username = trim((string)$input->argument('username'));
        $email = trim((string)$input->option('email', ''));
        $name = trim((string)$input->option('name', $username));
        $roles = UserRolesCommand::parseRoles((string)$input->option('roles', ''));

        if ($username === '' || mb_strlen($username) > 255 || preg_match('/\s|@/u', $username)) {
            $output->error('The username must not be empty and must not contain spaces or "@".');
            return 1;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->error('A valid --email is required.');
            return 1;
        }
        if ($roles === null) {
            $output->error('Roles may contain only letters, digits, ".", "_" and "-".');
            return 1;
        }

        $db = DB::getInstance();
        if ($db->selectOne('SELECT `id` FROM `frasm_users` WHERE `username` IN (?, ?) OR `email` IN (?, ?) LIMIT 1', [$username, $email, $username, $email]) !== null) {
            $output->error('A user with this username or email already exists.');
            return 1;
        }

        $password = $input->flag('generate') ? $this->generatePassword() : $this->askPassword($output);
        if ($password === null) {
            return 1;
        }

        $id = $db->insert('frasm_users', [
            'name'          => $name === '' ? $username : $name,
            'email'         => $email,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'permissions'   => implode(',', $roles),
        ]);

        $output->success("User '{$username}' created (id {$id}, roles: " . ($roles === [] ? 'none' : implode(', ', $roles)) . ')');
        if ($input->flag('generate')) {
            $output->line($password);
            $output->comment('Store it securely: it is not shown again.');
        }

        return 0;
    }
}
