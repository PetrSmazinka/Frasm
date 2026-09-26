<?php

declare(strict_types=1);

namespace Core\Logger;

use DateTimeImmutable;
use JsonSerializable;
use Stringable;
use Throwable;

/**
 * @file Logger.php
 * @brief Daily rotating file logger writing one JSON-context line per entry.
 */

/**
 * @class Logger
 * @brief Appends log entries to `<directory>/<channel>-YYYY-MM-DD.log` with atomic, locked writes.
 *
 * Line format: `[2026-09-26T21:13:00.123456+02:00] ERROR: Message {"key":"value"}`.
 * Messages are flattened to a single line (log-injection safe); context is JSON encoded.
 * Files older than the retention period are pruned whenever a new daily file is started.
 * The logger never throws: a failed write falls back to PHP's error_log().
 */
class Logger extends AbstractLogger
{
    /**
     * @var int Minimum priority that is written.
     */
    protected int $minPriority;

    /**
     * @brief Logger constructor.
     *
     * @param string $directory Target directory (created on demand).
     * @param string $minLevel Minimum level to record (LogLevel constant).
     * @param string $channel File name prefix.
     * @param int $retentionDays Days of history kept besides today (0 disables pruning).
     */
    public function __construct(
        protected string $directory,
        string $minLevel = LogLevel::DEBUG,
        protected string $channel = 'frasm',
        protected int $retentionDays = 14
    ) {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->minPriority = LogLevel::PRIORITIES[$minLevel] ?? LogLevel::PRIORITIES[LogLevel::DEBUG];
        $this->channel = preg_replace('/[^A-Za-z0-9_-]/', '', $channel) ?: 'frasm';
    }

    /**
     * @brief Records a log entry.
     *
     * @param string $level One of the LogLevel constants (unknown levels are recorded as 'error').
     * @param string|Stringable $message Message with optional {placeholders}.
     * @param array<string, mixed> $context Context data.
     * @return void
     */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        if (!LogLevel::isValid($level)) {
            $level = LogLevel::ERROR;
        }

        if (LogLevel::PRIORITIES[$level] < $this->minPriority) {
            return;
        }

        $line = $this->formatLine($level, (string)$message, $context);
        $this->write($line);
    }

    /**
     * @brief Returns the path of today's log file.
     *
     * @return string
     */
    public function currentFile(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->channel . '-' . date('Y-m-d') . '.log';
    }

    /**
     * @brief Builds a single log line.
     *
     * @param string $level Level name.
     * @param string $message Raw message.
     * @param array<string, mixed> $context Context data.
     * @return string Line terminated by a newline.
     */
    protected function formatLine(string $level, string $message, array $context): string
    {
        $message = $this->interpolate($message, $context);
        $message = str_replace(["\r\n", "\r", "\n"], ' ', $message);

        $timestamp = (new DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
        $line = "[{$timestamp}] " . strtoupper($level) . ": {$message}";

        if ($context !== []) {
            $encoded = json_encode(
                $this->normalize($context, 0),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        return $line . PHP_EOL;
    }

    /**
     * @brief Replaces {key} placeholders with scalar or stringable context values.
     *
     * @param string $message Message template.
     * @param array<string, mixed> $context Context data.
     * @return string
     */
    protected function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @brief Converts context values into JSON-safe structures.
     *
     * @param mixed $value Value to normalize.
     * @param int $depth Current recursion depth.
     * @return mixed
     */
    protected function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > 8) {
            return '[depth limit]';
        }

        if ($value instanceof Throwable) {
            return $this->normalizeThrowable($value, $depth);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->normalize($item, $depth + 1);
            }
            return $result;
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }

        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }

        return $value;
    }

    /**
     * @brief Serializes an exception chain without function arguments (which may contain secrets).
     *
     * @param Throwable $e Exception to serialize.
     * @param int $depth Current recursion depth.
     * @return array<string, mixed>
     */
    protected function normalizeThrowable(Throwable $e, int $depth): array
    {
        $trace = [];
        foreach ($e->getTrace() as $index => $frame) {
            $location = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[internal]';
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
            $trace[] = "#{$index} {$location} {$call}";
        }

        $data = [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
            'trace'   => $trace,
        ];

        if ($e->getPrevious() !== null) {
            $data['previous'] = $this->normalize($e->getPrevious(), $depth + 1);
        }

        return $data;
    }

    /**
     * @brief Appends a line to today's file, creating the directory and pruning old files as needed.
     *
     * @param string $line Formatted line.
     * @return void
     */
    protected function write(string $line): void
    {
        $file = $this->currentFile();
        $isNewFile = !is_file($file);

        if (!is_dir($this->directory)) {
            if (!@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                error_log('[Frasm Logger] Cannot create log directory: ' . $this->directory);
                error_log(rtrim($line));
                return;
            }
            // mkdir() mode is reduced by the umask; make the directory group-writable explicitly
            @chmod($this->directory, 0775);
        }

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('[Frasm Logger] Cannot write log file: ' . $file);
            error_log(rtrim($line));
            return;
        }

        if ($isNewFile) {
            // Allow both the web server and CLI users (same group) to append to the file
            @chmod($file, 0664);
            $this->pruneOldFiles();
        }
    }

    /**
     * @brief Deletes channel log files older than the retention period.
     *
     * @return void
     */
    protected function pruneOldFiles(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $threshold = date('Y-m-d', strtotime("-{$this->retentionDays} days"));
        $files = glob($this->directory . DIRECTORY_SEPARATOR . $this->channel . '-*.log') ?: [];

        foreach ($files as $file) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches) && $matches[1] < $threshold) {
                @unlink($file);
            }
        }
    }
}
