<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Exceptions\HttpException;

/**
 * @file FileResponse.php
 * @brief Response streaming a file from disk (downloads, inline documents, images, audio and video).
 */

/**
 * @class FileResponse
 * @brief Streams a file in chunks without loading it into memory and sets safe download headers.
 *
 * The response carries validators (strong ETag from inode, size and modification time, Last-Modified)
 * and `Accept-Ranges: bytes`. prepare(), called by the kernel for every response, answers conditional
 * GET/HEAD requests with 304 Not Modified (If-None-Match, If-Modified-Since) and single byte ranges
 * with 206 Partial Content (Range, If-Range) or 416 Range Not Satisfiable – media players need ranges
 * to seek. Requests for several ranges at once get the whole file (allowed by RFC 9110).
 *
 * Unless the application sets its own Cache-Control, browsers revalidate the file on every use
 * (`private, no-cache`): with the validators that costs a 304, and an edited file is never shown stale.
 * The session is closed before streaming, so a long download does not block the user's other requests.
 */
class FileResponse extends Response
{
    /**
     * @var int Size of the file in bytes (at construction).
     */
    protected int $size;

    /**
     * @var int First byte to send.
     */
    protected int $offset = 0;

    /**
     * @var int Number of bytes to send.
     */
    protected int $length;

    /**
     * @var (callable(): void)|null Called when streaming starts and then every $progressSeconds while it goes on.
     */
    protected $onProgress = null;

    /**
     * @var int Seconds between two calls of $onProgress.
     */
    protected int $progressSeconds = 5;

    /**
     * @brief FileResponse constructor.
     *
     * @param string $path Absolute path to a readable file. Never pass unvalidated user input (path traversal).
     * @param string|null $name File name presented to the client (defaults to basename).
     * @param string|null $contentType MIME type (auto-detected when null).
     * @param bool $inline Display inline instead of forcing a download.
     * @throws HttpException 404 when the file does not exist or is not readable.
     */
    public function __construct(
        protected string $path,
        ?string $name = null,
        ?string $contentType = null,
        bool $inline = false
    ) {
        $stat = is_file($path) && is_readable($path) ? @stat($path) : false;
        if ($stat === false) {
            throw new HttpException(404, "File not found: '{$path}'");
        }

        parent::__construct('', 200);

        $name ??= basename($path);
        $contentType ??= (function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null)
            ?? 'application/octet-stream';

        $this->size = (int)$stat['size'];
        $this->length = $this->size;

        $this->header('Content-Type', $contentType);
        $this->header('Content-Length', (string)$this->size);
        $this->header('Content-Disposition', self::contentDisposition($name, $inline));
        $this->header('X-Content-Type-Options', 'nosniff');
        $this->header('Accept-Ranges', 'bytes');
        $this->header('ETag', '"' . substr(hash('sha256', "{$stat['ino']}-{$stat['size']}-{$stat['mtime']}"), 0, 32) . '"');
        $this->header('Last-Modified', gmdate('D, d M Y H:i:s', (int)$stat['mtime']) . ' GMT');
        $this->header('Cache-Control', 'private, no-cache');
    }

    /**
     * @brief Returns the path of the streamed file.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @brief Builds an RFC 6266 Content-Disposition value with an ASCII fallback and UTF-8 file name.
     *
     * @param string $name Requested file name.
     * @param bool $inline Inline vs. attachment disposition.
     * @return string
     */
    public static function contentDisposition(string $name, bool $inline = false): string
    {
        $name = str_replace(["\r", "\n", "\0", '/', '\\'], '', $name);
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name);
        $fallback = $fallback === '' || $fallback === null ? 'download' : $fallback;

        return ($inline ? 'inline' : 'attachment')
            . '; filename="' . $fallback . '"'
            . "; filename*=UTF-8''" . rawurlencode($name);
    }

    /**
     * @brief Answers conditional and range requests (304, 206, 416); other requests stay a full 200.
     *
     * Validators set by the application (header('ETag', …), header('Last-Modified', …)) replace the default ones.
     *
     * @param Request $request Handled request.
     * @return static
     */
    public function prepare(Request $request): static
    {
        $method = $request->realMethod();
        if (($method !== 'GET' && $method !== 'HEAD') || $this->statusCode !== 200) {
            return $this;
        }

        if ($this->isNotModified($request)) {
            $this->setStatusCode(304);
            $this->length = 0;
            foreach (['Content-Type', 'Content-Length', 'Content-Disposition', 'Accept-Ranges'] as $header) {
                $this->removeHeader($header);
            }
            return $this;
        }

        $range = $request->header('Range');
        if ($range === null || !$this->ifRangeMatches($request)) {
            return $this;
        }

        $bounds = self::parseRange($range, $this->size);
        if ($bounds === null) {
            return $this;
        }

        if ($bounds === false) {
            $this->setStatusCode(416);
            $this->length = 0;
            $this->header('Content-Range', "bytes */{$this->size}");
            $this->header('Content-Length', '0');
            return $this;
        }

        [$this->offset, $last] = $bounds;
        $this->length = $last - $this->offset + 1;
        $this->setStatusCode(206);
        $this->header('Content-Range', "bytes {$this->offset}-{$last}/{$this->size}");
        $this->header('Content-Length', (string)$this->length);

        return $this;
    }

    /**
     * @brief Returns the bytes the response sends (the selected range), read into memory.
     *
     * Meant for tests and small files; sending uses streaming instead.
     *
     * @return string
     */
    public function contents(): string
    {
        if ($this->length === 0) {
            return '';
        }

        $data = @file_get_contents($this->path, false, null, $this->offset, $this->length);

        return $data === false ? '' : $data;
    }

    /**
     * @brief Parses a Range header against a representation size.
     *
     * Only one range in bytes is honoured (a list of ranges, another unit or a malformed value is ignored).
     *
     * @param string $header Range header value, e.g. "bytes=0-", "bytes=100-199" or "bytes=-500".
     * @param int $size Size of the representation in bytes.
     * @return array{0: int, 1: int}|false|null [first, last] byte (inclusive), false when unsatisfiable, null to ignore the header.
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        if (!preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/Di', $header, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            return null;
        }

        // More than 18 digits would overflow an integer: no file is that large
        $number = static fn(string $digits): int => strlen(ltrim($digits, '0')) > 18 ? PHP_INT_MAX : (int)$digits;

        if ($matches[1] === '') {
            $suffix = $number($matches[2]);
            if ($suffix === 0 || $size === 0) {
                return false;
            }
            return [max(0, $size - $suffix), $size - 1];
        }

        $first = $number($matches[1]);
        // An explicit end before the start is a malformed range (ignored), a start past the end unsatisfiable
        if ($matches[2] !== '' && $number($matches[2]) < $first) {
            return null;
        }
        if ($first >= $size) {
            return false;
        }

        return [$first, $matches[2] === '' ? $size - 1 : min($number($matches[2]), $size - 1)];
    }

    /**
     * @brief Evaluates If-None-Match (weak comparison) or, without it, If-Modified-Since.
     *
     * @param Request $request Handled request.
     * @return bool True when the client's copy is current.
     */
    protected function isNotModified(Request $request): bool
    {
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null) {
            $etag = $this->getHeader('ETag');
            if ($etag === null) {
                return false;
            }
            if (trim($ifNoneMatch) === '*') {
                return true;
            }
            $current = self::opaqueTag($etag);
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                if (self::opaqueTag($candidate) === $current) {
                    return true;
                }
            }
            return false;
        }

        $ifModifiedSince = $request->header('If-Modified-Since');
        $lastModified = $this->getHeader('Last-Modified');
        if ($ifModifiedSince === null || $lastModified === null) {
            return false;
        }

        $since = strtotime($ifModifiedSince);
        $modified = strtotime($lastModified);

        return $since !== false && $modified !== false && $modified <= $since;
    }

    /**
     * @brief Checks If-Range: a range is served only for the client's version of the file.
     *
     * @param Request $request Handled request.
     * @return bool True when there is no If-Range or it matches (strong ETag or exact date).
     */
    protected function ifRangeMatches(Request $request): bool
    {
        $ifRange = $request->header('If-Range');
        if ($ifRange === null) {
            return true;
        }

        $ifRange = trim($ifRange);
        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/')) {
            $etag = $this->getHeader('ETag');
            return $etag !== null && !str_starts_with($ifRange, 'W/') && !str_starts_with($etag, 'W/') && $ifRange === $etag;
        }

        $lastModified = $this->getHeader('Last-Modified');

        return $lastModified !== null && strtotime($ifRange) !== false && strtotime($ifRange) === strtotime($lastModified);
    }

    /**
     * @brief Returns an entity tag without its weakness indicator (for weak comparison).
     *
     * @param string $tag Entity tag, e.g. W/"abc" or "abc".
     * @return string
     */
    protected static function opaqueTag(string $tag): string
    {
        $tag = trim($tag);

        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }

    /**
     * @brief Calls a function while the file is being sent: when streaming starts and then every few seconds (e.g. to
     *        tell background jobs that media is playing, so they leave the disk to it).
     *
     * @param callable(): void $callback Function.
     * @param int $seconds Seconds between two calls.
     * @return static
     */
    public function onProgress(callable $callback, int $seconds = 5): static
    {
        $this->onProgress = $callback;
        $this->progressSeconds = max(1, $seconds);

        return $this;
    }

    /**
     * @brief Streams the selected bytes of the file in 64 KiB chunks.
     *
     * @return void
     */
    protected function sendContent(): void
    {
        if ($this->length === 0) {
            return;
        }

        // A download may take long: do not hold the session lock (it would block the user's other requests)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(0);

        // Drop any buffered output so the file is not held in memory and not corrupted
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if ($this->offset > 0 && fseek($handle, $this->offset) !== 0) {
                return;
            }

            $remaining = $this->length;
            $progressAt = 0;
            while ($remaining > 0 && !feof($handle)) {
                if ($this->onProgress !== null && time() - $progressAt >= $this->progressSeconds) {
                    $progressAt = time();
                    ($this->onProgress)();
                }
                $chunk = fread($handle, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $remaining -= strlen($chunk);
                echo $chunk;
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
