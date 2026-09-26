<?php

declare(strict_types=1);

namespace Core\Http;

use InvalidArgumentException;

/**
 * @file Response.php
 * @brief Object representation of the outgoing HTTP response.
 */

/**
 * @class Response
 * @brief Holds status code, headers and body, and emits them in one place via send().
 *
 * Controllers may return a Response directly or use the named constructors
 * (html(), json(), redirect(), download(), noContent()). Header names and values are
 * validated to prevent response splitting.
 */
class Response
{
    /**
     * @var array<int, string> Reason phrases for supported status codes.
     */
    protected const REASON_PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout', 409 => 'Conflict',
        410 => 'Gone', 411 => 'Length Required', 413 => 'Content Too Large', 415 => 'Unsupported Media Type',
        419 => 'Page Expired', 422 => 'Unprocessable Content', 423 => 'Locked', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    /**
     * @var array<string, array{name: string, values: list<string>}> Headers keyed by lowercase name.
     */
    protected array $headers = [];

    /**
     * @var int HTTP status code.
     */
    protected int $statusCode = 200;

    /**
     * @brief Response constructor.
     *
     * @param string $content Response body.
     * @param int $status HTTP status code.
     * @param array<string, string> $headers Response headers.
     * @throws InvalidArgumentException On invalid status code or header.
     */
    public function __construct(protected string $content = '', int $status = 200, array $headers = [])
    {
        $this->setStatusCode($status);
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    /**
     * @brief Creates an HTML response.
     *
     * @param string $html HTML markup.
     * @param int $status HTTP status code.
     * @param array<string, string> $headers Additional headers.
     * @return self
     */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /**
     * @brief Creates a JSON response.
     *
     * @param mixed $data Payload to encode.
     * @param int $status HTTP status code.
     * @param array<string, string> $headers Additional headers.
     * @param int $flags Extra json_encode flags (JSON_THROW_ON_ERROR is always applied).
     * @return self
     * @throws \JsonException If the payload cannot be encoded.
     */
    public static function json(mixed $data, int $status = 200, array $headers = [], int $flags = JSON_UNESCAPED_UNICODE): self
    {
        $body = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    /**
     * @brief Creates a redirect response.
     *
     * @param string $url Target URL or path. Must not be built from unvalidated user input (open redirect).
     * @param int $status Redirect status code (301, 302, 303, 307, 308).
     * @param array<string, string> $headers Additional headers.
     * @return self
     * @throws InvalidArgumentException On a non-redirect status code.
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException("Invalid redirect status code: {$status}.");
        }

        return new self('', $status, ['Location' => $url] + $headers);
    }

    /**
     * @brief Creates an empty 204 No Content response.
     *
     * @param array<string, string> $headers Additional headers.
     * @return self
     */
    public static function noContent(array $headers = []): self
    {
        return new self('', 204, $headers);
    }

    /**
     * @brief Creates a streamed file download response.
     *
     * @param string $path Absolute path to the file.
     * @param string|null $name Download file name presented to the client (defaults to basename).
     * @param string|null $contentType MIME type (auto-detected when null).
     * @param bool $inline When true, the browser is asked to display the file instead of saving it.
     * @return FileResponse
     */
    public static function download(string $path, ?string $name = null, ?string $contentType = null, bool $inline = false): FileResponse
    {
        return new FileResponse($path, $name, $contentType, $inline);
    }

    /**
     * @brief Creates a Server-Sent Events response.
     *
     * @param callable(EventStream): void $producer Callback emitting events; the stream ends when it returns.
     * @param int|null $maxDuration Stream duration in seconds (null = `sse.max_duration`).
     * @param string|null $lastEventId Last-Event-ID request header (for resuming).
     * @return EventStreamResponse
     */
    public static function eventStream(callable $producer, ?int $maxDuration = null, ?string $lastEventId = null): EventStreamResponse
    {
        return new EventStreamResponse($producer, $maxDuration, $lastEventId);
    }

    /**
     * @brief Returns the standard reason phrase for a status code.
     *
     * @param int $status HTTP status code.
     * @return string Reason phrase or an empty string for unknown codes.
     */
    public static function reasonPhrase(int $status): string
    {
        return self::REASON_PHRASES[$status] ?? '';
    }

    /**
     * @brief Returns the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @brief Sets the HTTP status code.
     *
     * @param int $status Status code in range 100-599.
     * @return static
     * @throws InvalidArgumentException On an out-of-range status code.
     */
    public function setStatusCode(int $status): static
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException("Invalid HTTP status code: {$status}.");
        }

        $this->statusCode = $status;
        return $this;
    }

    /**
     * @brief Returns the response body.
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @brief Replaces the response body.
     *
     * @param string $content New body.
     * @return static
     */
    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * @brief Sets or appends a response header.
     *
     * @param string $name Header name.
     * @param string $value Header value.
     * @param bool $replace When false, the value is appended (e.g. multiple Set-Cookie headers).
     * @return static
     * @throws InvalidArgumentException If the name or value contains illegal characters.
     */
    public function header(string $name, string $value, bool $replace = true): static
    {
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name)) {
            throw new InvalidArgumentException("Invalid HTTP header name: '{$name}'.");
        }
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException("Invalid characters in value of HTTP header '{$name}'.");
        }

        $key = strtolower($name);
        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = ['name' => $name, 'values' => [$value]];
        } else {
            $this->headers[$key]['values'][] = $value;
        }

        return $this;
    }

    /**
     * @brief Sets multiple headers at once (replacing existing values).
     *
     * @param array<string, string> $headers Header name => value.
     * @return static
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }

        return $this;
    }

    /**
     * @brief Returns the first value of a header.
     *
     * @param string $name Header name (case-insensitive).
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)]['values'][0] ?? null;
    }

    /**
     * @brief Checks whether a header is set.
     *
     * @param string $name Header name (case-insensitive).
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @brief Removes a header.
     *
     * @param string $name Header name (case-insensitive).
     * @return static
     */
    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);
        return $this;
    }

    /**
     * @brief Returns all headers.
     *
     * @return array<string, list<string>> Original header name => values.
     */
    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $header) {
            $result[$header['name']] = $header['values'];
        }

        return $result;
    }

    /**
     * @brief Emits status line, headers and body to the client.
     *
     * @param bool $withBody When false (HEAD requests), only the status and headers are sent.
     * @return void
     */
    public function send(bool $withBody = true): void
    {
        $this->sendHeaders();

        if ($withBody) {
            $this->sendContent();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * @brief Emits the status code and headers unless output has already started.
     *
     * @return void
     */
    protected function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $header) {
            $replace = true;
            foreach ($header['values'] as $value) {
                header("{$header['name']}: {$value}", $replace, $this->statusCode);
                $replace = false;
            }
        }
    }

    /**
     * @brief Emits the response body.
     *
     * @return void
     */
    protected function sendContent(): void
    {
        echo $this->content;
    }
}
