<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * @file AssetServer.php
 * @brief Serves the application's own static files (app/Assets) under the /assets/ URL.
 */

/**
 * @class AssetServer
 * @brief Keeps application scripts and styles out of public/, which belongs to the framework.
 *
 * Application assets live in app/Assets (for example app/Assets/css/app.css) and are referenced with
 * asset('css/app.css'), which returns "/assets/css/app.css?v=<modification time>". public/index.php
 * calls handle() before the framework boots, so an asset costs no session, routing or database work.
 *
 * - Only GET and HEAD, only whitelisted file types, no dot files, no path traversal (realpath check).
 * - Versioned URLs (?v=...) are cached by browsers for a year; unversioned ones are revalidated
 *   with ETag / Last-Modified and answered with 304 when unchanged.
 * - The class has no dependencies, because it runs before the autoloader exists.
 */
final class AssetServer
{
    /**
     * @var string URL prefix (after the application base path).
     */
    public const PREFIX = '/assets/';

    /**
     * @var array<string, string> Served file extensions and their content types.
     */
    private const TYPES = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'txt'   => 'text/plain; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
    ];

    /**
     * @brief Serves the request when it targets an asset.
     *
     * @param array<string, mixed> $server $_SERVER.
     * @param string $directory Asset directory (app/Assets).
     * @return bool True when the request was answered (also with 404/405), false when it is not an asset request.
     */
    public static function handle(array $server, string $directory): bool
    {
        $path = (string)parse_url((string)($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = self::basePath((string)($server['SCRIPT_NAME'] ?? ''));
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        if (!str_starts_with($path, self::PREFIX)) {
            return false;
        }

        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD');
            self::fail(405, 'Method Not Allowed');
            return true;
        }

        $file = self::resolve(rawurldecode(substr($path, strlen(self::PREFIX))), $directory);
        if ($file === null) {
            self::fail(404, 'Not Found');
            return true;
        }

        $mtime = (int)filemtime($file);
        $size = (int)filesize($file);
        $etag = sprintf('"%x-%x"', $mtime, $size);
        parse_str((string)parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $query);
        $versioned = isset($query['v']);

        header('Content-Type: ' . self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($versioned ? 'public, max-age=31536000, immutable' : 'no-cache'));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        $ifNoneMatch = (string)($server['HTTP_IF_NONE_MATCH'] ?? '');
        $ifModifiedSince = (string)($server['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ifNoneMatch !== '' ? in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)
            : ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
            http_response_code(304);
            return true;
        }

        header('Content-Length: ' . $size);
        if ($method === 'GET') {
            readfile($file);
        }

        return true;
    }

    /**
     * @brief Returns the public URL of an asset, versioned by its modification time.
     *
     * @param string $path Path inside app/Assets, e.g. "css/app.css".
     * @param string $base Application base path ('' at the web root).
     * @param string $directory Asset directory.
     * @return string
     */
    public static function url(string $path, string $base, string $directory): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $url = $base . rtrim(self::PREFIX, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        $file = $directory . DIRECTORY_SEPARATOR . $path;

        return is_file($file) ? $url . '?v=' . dechex((int)filemtime($file)) : $url;
    }

    /**
     * @brief Resolves a requested path to a servable file inside the asset directory.
     *
     * @param string $relative Requested path.
     * @param string $directory Asset directory.
     * @return string|null Absolute path, or null when the file must not or cannot be served.
     */
    private static function resolve(string $relative, string $directory): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')) {
            return null;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                return null;   // empty segments, "..", dot files (.htaccess, .git, ...)
            }
        }
        if (!isset(self::TYPES[strtolower(pathinfo($relative, PATHINFO_EXTENSION))])) {
            return null;
        }

        $root = realpath($directory);
        $file = realpath($directory . DIRECTORY_SEPARATOR . $relative);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    /**
     * @brief Base path of the front controller (same rule as Request::basePath()).
     *
     * @param string $scriptName SCRIPT_NAME.
     * @return string
     */
    private static function basePath(string $scriptName): string
    {
        $scriptDir = str_replace('\\', '/', dirname($scriptName));

        return ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');
    }

    /**
     * @brief Sends a short plain-text error.
     *
     * @param int $status HTTP status.
     * @param string $message Message.
     * @return void
     */
    private static function fail(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $message;
    }
}
