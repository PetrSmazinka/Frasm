<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * @file PasswordPrompt.php
 * @brief Password input shared by the user commands.
 */

/**
 * @trait PasswordPrompt
 * @brief Asks for a password without echo (or reads it from piped STDIN) or generates one.
 *
 * Passwords are never accepted as command-line arguments: they would end up in the shell history
 * and be visible to other users via `ps`.
 */
trait PasswordPrompt
{
    /**
     * @brief Asks for the password twice (or reads it once from piped STDIN) and validates it.
     *
     * @param Output $output Console output.
     * @param int $minLength Minimum length.
     * @return string|null Password, or null when input is missing or invalid.
     */
    protected function askPassword(Output $output, int $minLength = 8): ?string
    {
        $interactive = function_exists('stream_isatty') && @stream_isatty(STDIN);

        $password = $output->secret('Password:');
        if ($password === null || $password === '') {
            $output->error('No password given.');
            return null;
        }

        if (mb_strlen($password) < $minLength) {
            $output->error("The password must have at least {$minLength} characters.");
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
    protected function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }
}
