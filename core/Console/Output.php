<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * @file Output.php
 * @brief Consistent, optionally colored console output.
 */

/**
 * @class Output
 * @brief Writes messages, tables and prompts in the unified Frasm CLI style.
 *
 * Colors are used only when the stream is a terminal and the NO_COLOR environment variable is unset.
 * Errors and warnings go to STDERR, everything else to STDOUT. The class has no dependencies, so it is
 * also used by the installer before a project exists.
 */
final class Output
{
    /**
     * @var array<string, string> ANSI SGR codes of the supported styles.
     */
    private const STYLES = [
        'bold'   => '1',
        'dim'    => '2',
        'red'    => '31',
        'green'  => '32',
        'yellow' => '33',
        'cyan'   => '36',
    ];

    /**
     * @var resource Standard output stream.
     */
    private $stdout;

    /**
     * @var resource Error output stream.
     */
    private $stderr;

    /**
     * @var bool Whether ANSI colors are emitted.
     */
    private bool $decorated;

    /**
     * @brief Output constructor.
     *
     * @param resource|null $stdout Standard stream (default STDOUT).
     * @param resource|null $stderr Error stream (default STDERR).
     * @param bool|null $decorated Force colors on/off (null = detect terminal and NO_COLOR).
     */
    public function __construct($stdout = null, $stderr = null, ?bool $decorated = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->decorated = $decorated
            ?? (getenv('NO_COLOR') === false && function_exists('stream_isatty') && @stream_isatty($this->stdout));
    }

    /**
     * @brief Writes a plain line.
     *
     * @param string $message Text.
     * @return void
     */
    public function line(string $message = ''): void
    {
        fwrite($this->stdout, $message . PHP_EOL);
    }

    /**
     * @brief Writes a section title.
     *
     * @param string $message Title.
     * @return void
     */
    public function title(string $message): void
    {
        $this->line($this->style($message, 'bold'));
    }

    /**
     * @brief Writes an in-progress/informational step.
     *
     * @param string $message Text.
     * @return void
     */
    public function info(string $message): void
    {
        $this->line($this->style('›', 'cyan') . ' ' . $message);
    }

    /**
     * @brief Writes a success message.
     *
     * @param string $message Text.
     * @return void
     */
    public function success(string $message): void
    {
        $this->line($this->style('✔', 'green') . ' ' . $message);
    }

    /**
     * @brief Writes a warning to STDERR.
     *
     * @param string $message Text.
     * @return void
     */
    public function warning(string $message): void
    {
        fwrite($this->stderr, $this->style('!', 'yellow') . ' ' . $message . PHP_EOL);
    }

    /**
     * @brief Writes an error to STDERR.
     *
     * @param string $message Text.
     * @return void
     */
    public function error(string $message): void
    {
        fwrite($this->stderr, $this->style('✖', 'red') . ' ' . $message . PHP_EOL);
    }

    /**
     * @brief Writes a dimmed secondary line (hints, paths).
     *
     * @param string $message Text.
     * @return void
     */
    public function comment(string $message): void
    {
        $this->line($this->style($message, 'dim'));
    }

    /**
     * @brief Writes an aligned table.
     *
     * @param list<string> $headers Column headers.
     * @param list<list<string>> $rows Rows.
     * @return void
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string)$cell));
            }
        }

        $render = function (array $cells, bool $header) use ($widths): void {
            $line = '';
            foreach (array_values($cells) as $i => $cell) {
                $line .= (string)$cell . str_repeat(' ', $widths[$i] - mb_strlen((string)$cell) + 2);
            }
            $this->line($header ? $this->style(rtrim($line), 'bold') : rtrim($line));
        };

        $render($headers, true);
        foreach ($rows as $row) {
            $render($row, false);
        }
    }

    /**
     * @brief Asks a yes/no question on the terminal.
     *
     * @param string $question Question text.
     * @param bool $default Answer used for an empty reply or a non-interactive session.
     * @return bool
     */
    public function confirm(string $question, bool $default = false): bool
    {
        if (!function_exists('stream_isatty') || !@stream_isatty(STDIN)) {
            return $default;
        }

        fwrite($this->stdout, $this->style('?', 'yellow') . " {$question} " . ($default ? '[Y/n]' : '[y/N]') . ' ');
        $answer = strtolower(trim((string)fgets(STDIN)));

        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }

    /**
     * @brief Applies a style when decoration is enabled.
     *
     * @param string $text Text.
     * @param string $style Style name (bold, dim, red, green, yellow, cyan).
     * @return string
     */
    public function style(string $text, string $style): string
    {
        return $this->decorated && isset(self::STYLES[$style])
            ? "\033[" . self::STYLES[$style] . 'm' . $text . "\033[0m"
            : $text;
    }
}
