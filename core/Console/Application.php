<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Container\Container;
use Core\Exceptions\CoreException;
use Core\Frasm;
use Throwable;

/**
 * @file Application.php
 * @brief Console application behind bin/frasm.
 */

/**
 * @class Application
 * @brief Registers framework and application commands, renders help and dispatches invocations.
 *
 * Usage: `php bin/frasm <command> [arguments] [--options]`, `php bin/frasm list`,
 * `php bin/frasm help <command>` or `php bin/frasm <command> --help`.
 */
final class Application
{
    /**
     * @var list<class-string<Command>> Built-in commands.
     */
    private const COMMANDS = [
        Commands\AboutCommand::class,
        Commands\ServeCommand::class,
        Commands\KeyGenerateCommand::class,
        Commands\DbMigrateCommand::class,
        Commands\DbRollbackCommand::class,
        Commands\DbResetCommand::class,
        Commands\DbWipeCommand::class,
        Commands\DbSeedCommand::class,
        Commands\RouteListCommand::class,
        Commands\RouteCacheCommand::class,
        Commands\RouteClearCommand::class,
        Commands\QueueWorkCommand::class,
        Commands\QueueStatsCommand::class,
        Commands\QueueFailedCommand::class,
        Commands\QueueRetryCommand::class,
        Commands\QueueFlushCommand::class,
        Commands\PushVapidCommand::class,
        Commands\PushSendCommand::class,
        Commands\TokenCreateCommand::class,
        Commands\LogsArchiveCommand::class,
        Commands\PruneCommand::class,
    ];

    /**
     * @var array<string, string> Display names of command groups (prefix before ':').
     */
    private const GROUP_LABELS = [
        ''      => 'General',
        'db'    => 'Database',
        'key'   => 'Security',
        'token' => 'Security',
        'logs'  => 'Logging',
        'push'  => 'Web Push',
        'queue' => 'Queue',
        'route' => 'Routing',
    ];

    /**
     * @var array<string, class-string<Command>> Command name => class.
     */
    private array $commands = [];

    /**
     * @brief Application constructor.
     *
     * @param Container $container Container used to instantiate commands.
     * @param Output $output Console output.
     */
    public function __construct(private readonly Container $container, private readonly Output $output = new Output())
    {
        foreach (self::COMMANDS as $class) {
            $this->add($class);
        }

        foreach ($this->discoverApplicationCommands() as $class) {
            $this->add($class);
        }
    }

    /**
     * @brief Runs the command selected by the argument vector.
     *
     * @param list<string> $argv Raw arguments ($argv).
     * @return int Exit code.
     */
    public function run(array $argv): int
    {
        $tokens = array_slice($argv, 1);
        $name = $tokens[0] ?? 'list';

        if ($name === '--version' || $name === '-V') {
            $this->output->line('Frasm ' . Frasm::VERSION);
            return 0;
        }
        if ($name === 'list' || $name === '--help' || $name === '-h') {
            $this->renderList();
            return 0;
        }
        if ($name === 'help') {
            $name = $tokens[1] ?? 'list';
            if ($name === 'list') {
                $this->renderList();
                return 0;
            }
            $tokens = [$name, '--help'];
        }

        if (!isset($this->commands[$name])) {
            $this->output->error("Command '{$name}' is not defined.");
            $suggestions = array_filter(array_keys($this->commands), fn(string $candidate): bool => levenshtein($name, $candidate) <= 3 || str_starts_with($candidate, $name));
            if ($suggestions !== []) {
                $this->output->comment('  Did you mean: ' . implode(', ', $suggestions) . '?');
            }
            $this->output->comment('  Run `' . ($this->viaMake() ? 'make help' : 'php bin/frasm list') . '` to see all commands.');
            return 1;
        }

        try {
            /** @var Command $command */
            $command = $this->container->make($this->commands[$name]);
            $arguments = array_slice($tokens, 1);

            if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
                $this->renderHelp($command);
                return 0;
            }

            try {
                $input = Input::parse($arguments, $command->arguments(), $command->options());
            } catch (CoreException $e) {
                $this->output->error($e->getMessage());
                $this->output->comment('  See: ' . $this->usage($name, '--help'));
                return 1;
            }

            return $command->handle($input, $this->output);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());
            return 1;
        }
    }

    /**
     * @brief Registers a command class.
     *
     * @param class-string<Command> $class Command class.
     * @return void
     * @throws CoreException On duplicate names.
     */
    public function add(string $class): void
    {
        $name = $this->container->make($class)->name();
        if (isset($this->commands[$name]) && $this->commands[$name] !== $class) {
            throw new CoreException("Console command '{$name}' is defined twice ({$this->commands[$name]}, {$class}).");
        }

        $this->commands[$name] = $class;
    }

    /**
     * @brief Prints all commands grouped by namespace.
     *
     * @return void
     */
    private function renderList(): void
    {
        $this->output->title('Frasm ' . Frasm::VERSION);
        $this->output->line();
        if ($this->viaMake()) {
            $this->output->line('Usage: make <command> ARGS="[arguments] [--options]"');
            $this->output->comment('       make <command> ARGS=--help');
        } else {
            $this->output->line('Usage: php bin/frasm <command> [arguments] [--options]');
            $this->output->comment('       php bin/frasm help <command>');
        }

        $groups = [];
        foreach ($this->commands as $name => $class) {
            $prefix = str_contains($name, ':') ? explode(':', $name, 2)[0] : '';
            $group = self::GROUP_LABELS[$prefix] ?? ucfirst($prefix);
            $groups[$group][$name] = $this->container->make($class)->description();
        }
        uksort($groups, fn(string $a, string $b): int => $a === 'General' ? -1 : ($b === 'General' ? 1 : strcmp($a, $b)));

        $width = max(array_map('strlen', array_keys($this->commands)));
        foreach ($groups as $group => $commands) {
            ksort($commands);
            $this->output->line();
            $this->output->title($group);
            foreach ($commands as $name => $description) {
                $this->output->line('  ' . $this->output->style(str_pad($name, $width + 2), 'green') . $description);
            }
        }
    }

    /**
     * @brief Prints the detailed help of one command.
     *
     * @param Command $command Command.
     * @return void
     */
    private function renderHelp(Command $command): void
    {
        $parameters = [];
        foreach (array_keys($command->arguments()) as $argument) {
            $parameters[] = str_ends_with($argument, '?') ? '[<' . rtrim($argument, '?') . '>]' : "<{$argument}>";
        }
        if ($command->options() !== []) {
            $parameters[] = '[--options]';
        }
        $usage = $this->usage($command->name(), implode(' ', $parameters));

        $this->output->title($command->description());
        $this->output->line();
        $this->output->line("Usage: {$usage}");

        if ($command->arguments() !== []) {
            $this->output->line();
            $this->output->title('Arguments');
            foreach ($command->arguments() as $name => $description) {
                $this->output->line('  ' . $this->output->style(str_pad(rtrim($name, '?'), 22), 'green') . $description);
            }
        }

        if ($command->options() !== []) {
            $this->output->line();
            $this->output->title('Options');
            foreach ($command->options() as $name => $description) {
                $label = str_ends_with($name, '=') ? '--' . $name . '<value>' : '--' . $name;
                $this->output->line('  ' . $this->output->style(str_pad($label, 22), 'green') . $description);
            }
        }

        if ($command->help() !== '') {
            $this->output->line();
            foreach (explode("\n", $command->help()) as $line) {
                $this->output->line($line);
            }
        }
    }

    /**
     * @brief Checks whether the CLI was started through the project Makefile (make sets MAKELEVEL).
     *
     * @return bool
     */
    private function viaMake(): bool
    {
        return getenv('MAKELEVEL') !== false;
    }

    /**
     * @brief Formats an invocation in the style the user is using (make or php bin/frasm).
     *
     * @param string $command Command name.
     * @param string $parameters Arguments and options.
     * @return string
     */
    private function usage(string $command, string $parameters = ''): string
    {
        if ($this->viaMake()) {
            return "make {$command}" . ($parameters === '' ? '' : " ARGS=\"{$parameters}\"");
        }

        return trim("php bin/frasm {$command} {$parameters}");
    }

    /**
     * @brief Finds application commands (app/Commands/*Command.php, namespace App\Commands).
     *
     * @return list<class-string<Command>>
     */
    private function discoverApplicationCommands(): array
    {
        $directory = FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Commands';
        if (!is_dir($directory)) {
            return [];
        }

        $classes = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*Command.php') ?: [] as $file) {
            $class = 'App\\Commands\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
