<?php

declare(strict_types=1);

namespace Core\Http;

use InvalidArgumentException;

/**
 * @file EventStream.php
 * @brief Emitter for Server-Sent Events (text/event-stream) passed to Response::eventStream() producers.
 */

/**
 * @class EventStream
 * @brief Writes SSE frames, heartbeats and HTML fragment updates; tracks client disconnects and the time budget.
 *
 * Typical producer loop:
 * @code
 * return Response::eventStream(function (EventStream $stream) use ($sensors): void {
 *     do {
 *         $stream->html('#temperature', '<span id="temperature">' . $sensors->temperature() . ' °C</span>');
 *     } while ($stream->sleep(5));
 * });
 * @endcode
 */
final class EventStream
{
    /**
     * @var int Seconds of silence after which sleep() sends a heartbeat comment.
     */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * @var float Time of the last write (for heartbeats).
     */
    private float $lastWrite;

    /**
     * @var float Stream start time.
     */
    private float $startedAt;

    /**
     * @brief EventStream constructor.
     *
     * @param int $maxDuration Seconds after which the stream ends (the browser reconnects automatically).
     * @param string|null $lastEventId Value of the Last-Event-ID header sent on reconnect.
     */
    public function __construct(private readonly int $maxDuration, private readonly ?string $lastEventId = null)
    {
        $this->startedAt = $this->lastWrite = microtime(true);
    }

    /**
     * @brief Sends an event.
     *
     * @param mixed $data String payload, or any value that is JSON encoded.
     * @param string|null $event Event name (default 'message').
     * @param string|null $id Event id; the browser returns it as Last-Event-ID after a reconnect.
     * @return bool False when the client disconnected.
     * @throws InvalidArgumentException If event name or id contain line breaks.
     * @throws \JsonException If $data cannot be JSON encoded.
     */
    public function send(mixed $data, ?string $event = null, ?string $id = null): bool
    {
        $frame = '';
        if ($id !== null) {
            $frame .= 'id: ' . self::singleLine($id) . "\n";
        }
        if ($event !== null) {
            $frame .= 'event: ' . self::singleLine($event) . "\n";
        }

        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (preg_split('/\r\n|\r|\n/', $payload) ?: [''] as $line) {
            $frame .= 'data: ' . $line . "\n";
        }

        return $this->write($frame . "\n");
    }

    /**
     * @brief Sends an HTML fragment update applied by frasm-stream.js.
     *
     * @param string $target CSS selector of the element to update.
     * @param string $html New markup (must be escaped by the caller like any view output).
     * @param string $action replace (outerHTML) | update (innerHTML) | append | prepend | remove.
     * @param string|null $id Optional event id.
     * @return bool False when the client disconnected.
     * @throws InvalidArgumentException On an unknown action.
     */
    public function html(string $target, string $html, string $action = 'replace', ?string $id = null): bool
    {
        if (!in_array($action, ['replace', 'update', 'append', 'prepend', 'remove'], true)) {
            throw new InvalidArgumentException("Unknown stream HTML action '{$action}'.");
        }

        return $this->send(['target' => $target, 'action' => $action, 'html' => $html], 'frasm:html', $id);
    }

    /**
     * @brief Sends a comment line (ignored by clients, keeps proxies from closing the connection).
     *
     * @param string $text Comment text.
     * @return bool False when the client disconnected.
     */
    public function comment(string $text = ''): bool
    {
        return $this->write(': ' . self::singleLine($text) . "\n\n");
    }

    /**
     * @brief Tells the browser how long to wait before reconnecting.
     *
     * @param int $milliseconds Reconnect delay.
     * @return bool False when the client disconnected.
     */
    public function retry(int $milliseconds): bool
    {
        return $this->write('retry: ' . max(0, $milliseconds) . "\n\n");
    }

    /**
     * @brief Waits while sending heartbeats; use as the loop condition of a producer.
     *
     * @param float $seconds Time to wait.
     * @return bool False when the client disconnected or the time budget is exhausted (stop producing).
     */
    public function sleep(float $seconds): bool
    {
        $until = microtime(true) + max(0.0, $seconds);

        while (($now = microtime(true)) < $until) {
            if (!$this->isActive()) {
                return false;
            }
            if ($now - $this->lastWrite >= self::HEARTBEAT_INTERVAL && !$this->comment()) {
                return false;
            }
            usleep((int)(min(1.0, $until - $now) * 1_000_000));
        }

        return $this->isActive();
    }

    /**
     * @brief Checks that the client is connected and the time budget is not exhausted.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return connection_status() === CONNECTION_NORMAL
            && ($this->maxDuration <= 0 || microtime(true) - $this->startedAt < $this->maxDuration);
    }

    /**
     * @brief Returns the Last-Event-ID sent by a reconnecting browser.
     *
     * @return string|null
     */
    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * @brief Writes raw frame data and flushes it to the client.
     *
     * @param string $data Frame data.
     * @return bool False when the client disconnected.
     */
    private function write(string $data): bool
    {
        echo $data;
        flush();
        $this->lastWrite = microtime(true);

        return connection_status() === CONNECTION_NORMAL;
    }

    /**
     * @brief Rejects values that would break the SSE framing.
     *
     * @param string $value Field value.
     * @return string
     * @throws InvalidArgumentException If the value contains line breaks.
     */
    private static function singleLine(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('SSE field values must not contain line breaks.');
        }

        return $value;
    }
}
