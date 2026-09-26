<?php

declare(strict_types=1);

namespace Core\Http;

use Closure;
use Core\Config\Config;
use Throwable;

/**
 * @file EventStreamResponse.php
 * @brief Streaming response for Server-Sent Events.
 */

/**
 * @class EventStreamResponse
 * @brief Keeps the connection open and lets a producer push events through an EventStream.
 *
 * Before streaming, the session is closed (its file lock would block every other request of the
 * user), output buffers and compression are disabled and the script time limit is aligned with
 * the stream duration. Each open stream occupies one Apache worker: keep `sse.max_duration`
 * moderate; browsers reconnect automatically (with Last-Event-ID).
 */
class EventStreamResponse extends Response
{
    /**
     * @var Closure(EventStream): void Event producer.
     */
    protected Closure $producer;

    /**
     * @brief EventStreamResponse constructor.
     *
     * @param callable(EventStream): void $producer Callback emitting events until it returns.
     * @param int|null $maxDuration Stream duration in seconds (null = `sse.max_duration`, default 300).
     * @param string|null $lastEventId Last-Event-ID request header.
     */
    public function __construct(callable $producer, protected ?int $maxDuration = null, protected ?string $lastEventId = null)
    {
        parent::__construct('', 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);

        $this->producer = Closure::fromCallable($producer);
    }

    /**
     * @brief Streams events produced by the callback.
     *
     * @return void
     */
    protected function sendContent(): void
    {
        $duration = $this->maxDuration ?? (int)Config::get('sse.max_duration', 300);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ignore_user_abort(true);
        set_time_limit($duration > 0 ? $duration + 30 : 0);

        $stream = new EventStream($duration, $this->lastEventId);

        try {
            $stream->comment('frasm stream');
            ($this->producer)($stream);
        } catch (Throwable $e) {
            // Headers are already sent: report the failure to the log and end the stream cleanly
            \Core\Logger\Log::error('Event stream producer failed: ' . $e->getMessage(), ['exception' => $e]);
            if (connection_status() === CONNECTION_NORMAL) {
                $stream->send(['message' => 'Stream error'], 'frasm:error');
            }
        }
    }
}
