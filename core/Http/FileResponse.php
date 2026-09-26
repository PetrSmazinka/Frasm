<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Exceptions\HttpException;

/**
 * @file FileResponse.php
 * @brief Response streaming a file from disk (downloads, inline documents, images).
 */

/**
 * @class FileResponse
 * @brief Streams a file in chunks without loading it into memory and sets safe download headers.
 */
class FileResponse extends Response
{
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
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException(404, "File not found: '{$path}'");
        }

        parent::__construct('', 200);

        $name ??= basename($path);
        $contentType ??= (function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null)
            ?? 'application/octet-stream';

        $this->header('Content-Type', $contentType);
        $this->header('Content-Length', (string)filesize($path));
        $this->header('Content-Disposition', self::contentDisposition($name, $inline));
        $this->header('X-Content-Type-Options', 'nosniff');
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
     * @brief Streams the file content in 64 KiB chunks.
     *
     * @return void
     */
    protected function sendContent(): void
    {
        // Drop any buffered output so the file is not held in memory and not corrupted
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();
            }
        } finally {
            fclose($handle);
        }
    }
}
