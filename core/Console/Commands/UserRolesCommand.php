<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\DB\DB;

/**
 * @file UserRolesCommand.php
 * @brief Shows or changes the roles of a user account.
 */

/**
 * @class UserRolesCommand
 * @brief Adds, removes or replaces the roles (`frasm_users.permissions`) of a user.
 *
 * Roles are read at login, so a change applies to the user's next login; signed-in sessions keep
 * their roles until then.
 */
final class UserRolesCommand extends Command
{
    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'user:roles';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Show or change the roles of a user';
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
            'add='    => 'Comma-separated roles to add',
            'remove=' => 'Comma-separated roles to remove',
            'set='    => 'Replace all roles (empty value removes all)',
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
            . "  php bin/frasm user:roles jana                       # show the roles\n"
            . "  php bin/frasm user:roles jana --add=reports.view\n"
            . "  php bin/frasm user:roles jana --remove=editor";
    }

    /**
     * @brief Shows or changes the roles.
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
            'SELECT `id`, `username`, `permissions` FROM `frasm_users` WHERE `username` = ? OR `email` = ? LIMIT 1',
            [$identifier, $identifier]
        );
        if ($user === null) {
            $output->error("User '{$identifier}' does not exist.");
            return 1;
        }

        $current = self::parseRoles((string)$user['permissions']) ?? [];
        $set = $input->option('set');
        $add = self::parseRoles((string)$input->option('add', ''));
        $remove = self::parseRoles((string)$input->option('remove', ''));
        $replacement = $set === null ? [] : self::parseRoles($set);

        if ($add === null || $remove === null || $replacement === null) {
            $output->error('Roles may contain only letters, digits, ".", "_" and "-".');
            return 1;
        }

        $roles = $set === null ? $current : $replacement;
        $roles = array_values(array_diff(array_unique([...$roles, ...$add]), $remove));

        if ($roles !== $current) {
            $value = implode(',', $roles);
            if (strlen($value) > 1000) {
                $output->error('Too many roles (the list is limited to 1000 characters).');
                return 1;
            }
            $db->update('frasm_users', ['permissions' => $value], '`id` = ?', [$user['id']]);
            $output->success("Roles of '{$user['username']}' changed; they apply at the user's next login");
        }

        $output->line($roles === [] ? '(no roles)' : implode(', ', $roles));

        return 0;
    }

    /**
     * @brief Parses a comma-separated list of roles.
     *
     * @param string $list Roles, e.g. "editor, reports.view".
     * @return list<string>|null Unique roles, or null when a role contains invalid characters.
     */
    public static function parseRoles(string $list): ?array
    {
        $roles = [];
        foreach (explode(',', $list) as $role) {
            $role = trim($role);
            if ($role === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $role)) {
                return null;
            }
            $roles[$role] = true;
        }

        return array_keys($roles);
    }
}
