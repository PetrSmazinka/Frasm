<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\DB;

/**
 * @file UserPasswordCommand.php
 * @brief Sets a new password for a user account.
 */

/**
 * @class UserPasswordCommand
 * @brief Changes the password of a user in `frasm_users` and revokes their remember-me tokens.
 *
 * The password is never accepted as a command-line argument (it would end up in the shell history
 * and be visible to other users via `ps`). It is asked twice on the terminal without echo, read from
 * STDIN when piped, or generated with --generate.
 */
final class UserPasswordCommand extends Command
{
    /**
     * @var int Minimum password length.
     */
    private const MIN_LENGTH = 8;

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'user:password';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Set a new password for a user account';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['user' => 'Username or email'];
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'generate'     => 'Generate a random password and print it',
            'keep-devices' => 'Do not sign the user out of "remember me" devices',
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
            . "  php bin/frasm user:password admin                 # asks for the new password\n"
            . "  php bin/frasm user:password admin --generate      # prints a random password\n"
            . "  printf '%s\\n' \"\$PASS\" | php bin/frasm user:password admin   # scripts";
    }

    /**
     * @brief Changes the password.
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

        $identifier = (string)$input->argument('user');
        $db = DB::getInstance();
        $user = $db->selectOne(
            'SELECT `id`, `username` FROM `frasm_users` WHERE `username` = ? OR `email` = ? LIMIT 1',
            [$identifier, $identifier]
        );

        if ($user === null) {
            $output->error("User '{$identifier}' does not exist.");
            return 1;
        }

        $password = $input->flag('generate') ? $this->generate() : $this->ask($output);
        if ($password === null) {
            return 1;
        }

        $db->update('frasm_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], '`id` = ?', [$user['id']]);
        $output->success("Password of '{$user['username']}' changed");

        if ($input->flag('generate')) {
            $output->line($password);
            $output->comment('Store it securely: it is not shown again.');
        }

        if (!$input->flag('keep-devices')) {
            $revoked = $db->delete('frasm_remember_tokens', '`user_id` = ?', [$user['id']]);
            $output->success("Signed out of {$revoked} remembered device(s)");
        }

        return 0;
    }

    /**
     * @brief Asks for the password twice (or reads it once from piped STDIN) and validates it.
     *
     * @param Output $output Console output.
     * @return string|null Password, or null when input is missing or invalid.
     */
    private function ask(Output $output): ?string
    {
        $interactive = function_exists('stream_isatty') && @stream_isatty(STDIN);

        $password = $output->secret('New password:');
        if ($password === null || $password === '') {
            $output->error('No password given.');
            return null;
        }

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $output->error('The password must have at least ' . self::MIN_LENGTH . ' characters.');
            return null;
        }

        if ($interactive && $output->secret('Repeat the password:') !== $password) {
            $output->error('The passwords do not match.');
            return null;
        }

        return $password;
    }

    /**
     * @brief Generates a random URL-safe password.
     *
     * @return string 16 characters (96 bits of entropy).
     */
    private function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }
}
