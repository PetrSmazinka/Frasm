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
 *
 * SD card friendly operation (Raspberry Pi):
 *  - Entries are buffered in memory and written with a single append per request (at shutdown,
 *    when the buffer exceeds 64 KiB, or immediately for critical and higher levels).
 *  - The log directory may live on tmpfs (e.g. /dev/shm/frasm/logs); archive() then moves the
 *    content to persistent storage (`php bin/frasm logs:archive` from cron).
 */
class Logger extends AbstractLogger
{
    /**
     * @var int Minimum priority that is written.
     */
    protected int $minPriority;

    /**
     * @var int Buffer size that triggers an immediate flush.
     */
    protected const BUFFER_LIMIT = 65536;

    /**
     * @var array<string, string> Pending lines grouped by target file.
     */
    protected array $buffer = [];

    /**
     * @var int Size of pending lines in bytes.
     */
    protected int $bufferedBytes = 0;

    /**
     * @var bool Whether the shutdown flush has been registered.
     */
    protected bool $shutdownRegistered = false;

    /**
     * @brief Logger constructor.
     *
     * @param string $directory Target directory (created on demand).
     * @param string $minLevel Minimum level to record (LogLevel constant).
     * @param string $channel File name prefix.
     * @param int $retentionDays Days of history kept besides today (0 disables pruning).
     * @param bool $buffered Buffer entries and write them once per request.
     * @param string|null $archiveDirectory Persistent directory used by archive() (null = no archiving).
     */
    public function __construct(
        protected string $directory,
        string $minLevel = LogLevel::DEBUG,
        protected string $channel = 'frasm',
        protected int $retentionDays = 14,
        protected bool $buffered = true,
        protected ?string $archiveDirectory = null
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

        if (!$this->buffered) {
            $this->write($this->currentFile(), $line);
            return;
        }

        $file = $this->currentFile();
        $this->buffer[$file] = ($this->buffer[$file] ?? '') . $line;
        $this->bufferedBytes += strlen($line);

        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'flush']);
            $this->shutdownRegistered = true;
        }

        if ($this->bufferedBytes >= self::BUFFER_LIMIT || LogLevel::PRIORITIES[$level] >= LogLevel::PRIORITIES[LogLevel::CRITICAL]) {
            $this->flush();
        }
    }

    /**
     * @brief Writes all buffered entries (one append per target file).
     *
     * Called automatically at shutdown; long-running processes (queue worker) call it periodically.
     *
     * @return void
     */
    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        $this->bufferedBytes = 0;

        foreach ($buffer as $file => $lines) {
            $this->write($file, $lines);
        }
    }

    /**
     * @brief Moves log content from the (volatile) log directory into the archive directory.
     *
     * Each file is locked while being copied and truncated, so concurrent writers never lose lines.
     * Files of previous days are removed from the volatile directory afterwards.
     *
     * @return int Number of bytes archived.
     */
    public function archive(): int
    {
        $this->flush();

        if ($this->archiveDirectory === null) {
            return 0;
        }

        $archive = rtrim($this->archiveDirectory, DIRECTORY_SEPARATOR);
        if (realpath($archive) !== false && realpath($archive) === realpath($this->directory)) {
            return 0;
        }
        if (!is_dir($archive) && !@mkdir($archive, 0775, true) && !is_dir($archive)) {
            error_log('[Frasm Logger] Cannot create archive directory: ' . $archive);
            return 0;
        }

        $moved = 0;
        $today = $this->channel . '-' . date('Y-m-d') . '.log';

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . $this->channel . '-*.log') ?: [] as $file) {
            $handle = @fopen($file, 'r+');
            if ($handle === false) {
                continue;
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    continue;
                }

                $content = stream_get_contents($handle);
                if (is_string($content) && $content !== '') {
                    $target = $archive . DIRECTORY_SEPARATOR . basename($file);
                    if (@file_put_contents($target, $content, FILE_APPEND | LOCK_EX) === false) {
                        error_log('[Frasm Logger] Cannot append to archive file: ' . $target);
                        continue;
                    }
                    @chmod($target, 0664);
                    ftruncate($handle, 0);
                    $moved += strlen($content);
                }

                if (basename($file) !== $today) {
                    @unlink($file);
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        $this->pruneOldFiles($archive);

        return $moved;
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
     * @brief Appends lines to a log file, creating the directory and pruning old files as needed.
     *
     * @param string $file Target file.
     * @param string $line Formatted line(s).
     * @return void
     */
    protected function write(string $file, string $line): void
    {
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
            $this->pruneOldFiles($this->directory);
        }
    }

    /**
     * @brief Deletes channel log files older than the retention period.
     *
     * @param string $directory Directory to prune.
     * @return void
     */
    protected function pruneOldFiles(string $directory): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $threshold = date('Y-m-d', strtotime("-{$this->retentionDays} days"));
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->channel . '-*.log') ?: [];

        foreach ($files as $file) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches) && $matches[1] < $threshold) {
                @unlink($file);
            }
        }
    }
}
