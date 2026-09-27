<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Exceptions\CoreException;

/**
 * @file Input.php
 * @brief Parsed command-line arguments and options.
 */

/**
 * @class Input
 * @brief Positional arguments and `--name[=value]` options of one command invocation.
 */
final class Input
{
    /**
     * @brief Input constructor.
     *
     * @param array<string, string> $arguments Positional arguments by name.
     * @param array<string, string|bool> $options Options by name (true for flags).
     */
    public function __construct(
        private readonly array $arguments,
        private readonly array $options
    ) {
    }

    /**
     * @brief Parses raw tokens against a command definition.
     *
     * @param list<string> $tokens Tokens after the command name.
     * @param array<string, string> $argumentDefinitions Argument name (suffix '?' = optional) => description.
     * @param array<string, string> $optionDefinitions Option name (suffix '=' = takes a value) => description.
     * @return self
     * @throws CoreException On unknown options, missing values or missing required arguments.
     */
    public static function parse(array $tokens, array $argumentDefinitions, array $optionDefinitions): self
    {
        $flags = [];
        $valued = [];
        foreach (array_keys($optionDefinitions) as $definition) {
            str_ends_with($definition, '=') ? $valued[rtrim($definition, '=')] = true : $flags[$definition] = true;
        }

        $positional = [];
        $options = [];

        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                $positional[] = $token;
                continue;
            }

            [$name, $value] = str_contains($token, '=') ? explode('=', substr($token, 2), 2) : [substr($token, 2), null];

            if (isset($valued[$name])) {
                if ($value === null || $value === '') {
                    throw new CoreException("Option --{$name} requires a value (--{$name}=...).");
                }
                $options[$name] = $value;
            } elseif (isset($flags[$name])) {
                if ($value !== null) {
                    throw new CoreException("Option --{$name} does not take a value.");
                }
                $options[$name] = true;
            } else {
                throw new CoreException("Unknown option --{$name}.");
            }
        }

        $arguments = [];
        $names = array_keys($argumentDefinitions);
        foreach ($names as $index => $definition) {
            $optional = str_ends_with($definition, '?');
            $name = rtrim($definition, '?');

            if (array_key_exists($index, $positional)) {
                $arguments[$name] = $positional[$index];
            } elseif (!$optional) {
                throw new CoreException("Missing argument <{$name}>.");
            }
        }

        if (count($positional) > count($names)) {
            throw new CoreException('Too many arguments.');
        }

        return new self($arguments, $options);
    }

    /**
     * @brief Returns a positional argument.
     *
     * @param string $name Argument name.
     * @param string|null $default Fallback value.
     * @return string|null
     */
    public function argument(string $name, ?string $default = null): ?string
    {
        return $this->arguments[$name] ?? $default;
    }

    /**
     * @brief Returns an option value.
     *
     * @param string $name Option name.
     * @param string|null $default Fallback value.
     * @return string|null
     */
    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * @brief Returns an integer option value.
     *
     * @param string $name Option name.
     * @param int $default Fallback value.
     * @return int
     * @throws CoreException If the value is not an integer.
     */
    public function intOption(string $name, int $default): int
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new CoreException("Option --{$name} must be an integer.");
        }

        return (int)$value;
    }

    /**
     * @brief Checks whether a flag option was given.
     *
     * @param string $name Option name.
     * @return bool
     */
    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) === true;
    }
}
