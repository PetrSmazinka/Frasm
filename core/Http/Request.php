<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Config\Config;

/**
 * @file Request.php
 * @brief Object representation of the incoming HTTP request.
 */

/**
 * @class Request
 * @brief Encapsulates query/body parameters, headers, cookies, uploaded files and server data.
 *
 * Replaces direct access to PHP superglobals. Values read from the client are always untrusted:
 * cookie and header accessors return strings only, so array-injection tricks such as
 * `?cookie[]=x` cannot cause type errors downstream. Request attributes are a mutable,
 * server-side bag used by the router and middleware (e.g. matched route, route parameters).
 */
class Request
{
    /**
     * @var list<string> HTTP methods that may be tunnelled through a POST form field `_method`.
     */
    protected const OVERRIDABLE_METHODS = ['PUT', 'PATCH', 'DELETE'];

    /**
     * @var array<string, string> Normalized request headers (lowercase name => value).
     */
    protected array $headers;

    /**
     * @var array<string, mixed> Server-side attributes set during request processing.
     */
    protected array $attributes = [];

    /**
     * @var mixed Cached decoded JSON body (false = not parsed yet).
     */
    protected mixed $decodedJson = false;

    /**
     * @var string|null Cached normalized path.
     */
    protected ?string $path = null;

    /**
     * @brief Request constructor.
     *
     * @param array<string, mixed> $query Query string parameters ($_GET).
     * @param array<string, mixed> $post Form body parameters ($_POST).
     * @param array<string, mixed> $cookies Cookies ($_COOKIE).
     * @param array<string, mixed> $files Uploaded files ($_FILES).
     * @param array<string, mixed> $server Server and execution environment ($_SERVER).
     * @param string|null $content Raw request body; null means lazily read from php://input.
     */
    public function __construct(
        protected array $query = [],
        protected array $post = [],
        protected array $cookies = [],
        protected array $files = [],
        protected array $server = [],
        protected ?string $content = null
    ) {
        $this->headers = $this->extractHeaders($server);
    }

    /**
     * @brief Creates a request instance from PHP superglobals.
     *
     * @return static
     */
    public static function fromGlobals(): static
    {
        return new static($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
    }

    /**
     * @brief Returns the effective HTTP method (uppercase), honoring the `_method` override for POST forms.
     *
     * @return string
     */
    public function method(): string
    {
        $method = $this->realMethod();

        if ($method === 'POST') {
            $override = $this->post['_method'] ?? null;
            if (is_string($override) && in_array(strtoupper($override), self::OVERRIDABLE_METHODS, true)) {
                return strtoupper($override);
            }
        }

        return $method;
    }

    /**
     * @brief Returns the HTTP method sent on the wire, ignoring method overrides.
     *
     * @return string
     */
    public function realMethod(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * @brief Checks the effective HTTP method.
     *
     * @param string $method Method name (case-insensitive).
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * @brief Checks whether the request uses a safe (read-only) HTTP method.
     *
     * @return bool True for GET, HEAD and OPTIONS.
     */
    public function isMethodSafe(): bool
    {
        return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * @brief Returns the raw request URI including the query string.
     *
     * @return string
     */
    public function uri(): string
    {
        return (string)($this->server['REQUEST_URI'] ?? '/');
    }

    /**
     * @brief Returns the application base path when the front controller lives in a subdirectory.
     *
     * @return string Base path without trailing slash, or an empty string when served from the web root.
     */
    public function basePath(): string
    {
        $scriptDir = str_replace('\\', '/', dirname((string)($this->server['SCRIPT_NAME'] ?? '')));
        return ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');
    }

    /**
     * @brief Returns the normalized request path relative to the application base path.
     *
     * The result always starts with '/' and never ends with '/' (except for the root path).
     *
     * @return string
     */
    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $path = parse_url($this->uri(), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Strip subdirectory prefix only on a full segment boundary ('/app' must not strip '/application')
        $basePath = $this->basePath();
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }

        $normalized = '/' . trim($path, '/');
        return $this->path = $normalized;
    }

    /**
     * @brief Reads a query string parameter.
     *
     * @param string|null $key Parameter name, or null for all query parameters.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /**
     * @brief Reads a form body parameter ($_POST).
     *
     * @param string|null $key Parameter name, or null for all form parameters.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    /**
     * @brief Reads a body parameter from a JSON payload or form submission, whichever the request carries.
     *
     * @param string|null $key Parameter name (dot notation supported for nested JSON), or null for all.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->inputData();

        if ($key === null) {
            return $data;
        }

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @brief Returns merged query and body parameters (body wins on key collisions).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->inputData());
    }

    /**
     * @brief Checks whether the body (or query) contains the given key.
     *
     * @param string $key Parameter name.
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @brief Decodes the JSON request body.
     *
     * @param bool $associative When true, JSON objects are returned as associative arrays.
     * @return mixed Decoded payload, or null for an empty or malformed body.
     */
    public function json(bool $associative = true): mixed
    {
        if (!$associative) {
            return $this->decodeJson(false);
        }

        if ($this->decodedJson === false) {
            $this->decodedJson = $this->decodeJson(true);
        }

        return $this->decodedJson;
    }

    /**
     * @brief Returns the raw request body.
     *
     * @return string
     */
    public function content(): string
    {
        if ($this->content === null) {
            $raw = file_get_contents('php://input');
            $this->content = $raw === false ? '' : $raw;
        }

        return $this->content;
    }

    /**
     * @brief Reads a request header.
     *
     * @param string $name Header name (case-insensitive).
     * @param string|null $default Fallback value.
     * @return string|null
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @brief Returns all request headers.
     *
     * @return array<string, string> Lowercase header name => value.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @brief Extracts the token from an `Authorization: Bearer <token>` header.
     *
     * @return string|null Token or null when absent.
     */
    public function bearerToken(): ?string
    {
        $header = (string)$this->header('Authorization', '');
        if (strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token === '' ? null : $token;
    }

    /**
     * @brief Reads a cookie value.
     *
     * @param string $name Cookie name.
     * @param string|null $default Fallback value.
     * @return string|null Cookie value; non-string (array) cookies are ignored.
     */
    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * @brief Returns metadata of an uploaded file.
     *
     * @param string $key Form field name.
     * @return array<string, mixed>|null $_FILES entry or null.
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    /**
     * @brief Reads a server/environment value.
     *
     * @param string $key $_SERVER key.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * @brief Resolves the client IP address.
     *
     * X-Forwarded-For is honored only when REMOTE_ADDR belongs to `app.trusted_proxies`
     * (list of IPs or CIDR ranges). The right-most untrusted address in the chain is returned,
     * which cannot be spoofed by the client.
     *
     * @return string Client IP address ('0.0.0.0' when unknown).
     */
    public function ip(): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        /** @var list<string> $trusted */
        $trusted = (array)Config::get('app.trusted_proxies', []);
        if ($trusted === [] || !self::ipMatches($remote, $trusted)) {
            return $remote;
        }

        $forwarded = (string)$this->header('X-Forwarded-For', '');
        $chain = array_reverse(array_filter(array_map('trim', explode(',', $forwarded))));

        foreach ($chain as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::ipMatches($candidate, $trusted)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * @brief Checks whether the request was made over HTTPS (directly or via a trusted proxy).
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        $https = strtolower((string)($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        /** @var list<string> $trusted */
        $trusted = (array)Config::get('app.trusted_proxies', []);
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '');

        return $trusted !== []
            && self::ipMatches($remote, $trusted)
            && strtolower((string)$this->header('X-Forwarded-Proto', '')) === 'https';
    }

    /**
     * @brief Returns the host name requested by the client (without port).
     *
     * @return string
     */
    public function host(): string
    {
        $host = (string)($this->header('Host') ?? $this->server['SERVER_NAME'] ?? '');
        return strtolower((string)preg_replace('/:\d+$/', '', $host));
    }

    /**
     * @brief Returns the Content-Type media type without parameters (e.g. 'application/json').
     *
     * @return string
     */
    public function contentType(): string
    {
        $contentType = (string)$this->header('Content-Type', '');
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    /**
     * @brief Checks whether the request body is JSON.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $type = $this->contentType();
        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    /**
     * @brief Checks whether the request was sent via XMLHttpRequest/fetch with the X-Requested-With header.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return strtolower((string)$this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * @brief Determines whether the client prefers a JSON response.
     *
     * @return bool True for `Accept: application/json` or AJAX requests.
     */
    public function expectsJson(): bool
    {
        return str_contains(strtolower((string)$this->header('Accept', '')), 'application/json') || $this->isAjax();
    }

    /**
     * @brief Checks whether the request was issued by frasm-nav.js (HTML-over-the-wire navigation).
     *
     * @return bool
     */
    public function isFrasmRequest(): bool
    {
        return $this->header('X-Frasm-Request') === '1';
    }

    /**
     * @brief Returns the CSS selector of the fragment the client is going to replace (data-frasm-target).
     *
     * Controllers may use it to render only that fragment; the response must still contain an element
     * matching the selector. Responses that differ by it should send `Vary: X-Frasm-Target`.
     *
     * @return string|null
     */
    public function frasmTarget(): ?string
    {
        $target = $this->header('X-Frasm-Target');
        return $target === null || $target === '' ? null : $target;
    }

    /**
     * @brief Checks whether the request is a speculative hover prefetch (must be free of side effects).
     *
     * @return bool
     */
    public function isPrefetch(): bool
    {
        return $this->header('X-Frasm-Prefetch') === '1';
    }

    /**
     * @brief Reads a server-side request attribute.
     *
     * @param string $name Attribute name.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * @brief Stores a server-side request attribute.
     *
     * @param string $name Attribute name.
     * @param mixed $value Attribute value.
     * @return static
     */
    public function setAttribute(string $name, mixed $value): static
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    /**
     * @brief Checks whether an IP address matches any of the given addresses or CIDR ranges.
     *
     * @param string $ip Address to test (IPv4 or IPv6).
     * @param list<string> $ranges Exact addresses or CIDR notations.
     * @return bool
     */
    public static function ipMatches(string $ip, array $ranges): bool
    {
        $binaryIp = @inet_pton($ip);
        if ($binaryIp === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $binarySubnet = @inet_pton($subnet);

            if ($binarySubnet === false || strlen($binarySubnet) !== strlen($binaryIp)) {
                continue;
            }

            $maxBits = strlen($binaryIp) * 8;
            $bits = $bits === null ? $maxBits : (int)$bits;
            if ($bits < 0 || $bits > $maxBits) {
                continue;
            }

            $fullBytes = intdiv($bits, 8);
            if (substr($binaryIp, 0, $fullBytes) !== substr($binarySubnet, 0, $fullBytes)) {
                continue;
            }

            $remainder = $bits % 8;
            if ($remainder === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($binaryIp[$fullBytes]) & $mask) === (ord($binarySubnet[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Returns body parameters from the JSON payload or the form submission.
     *
     * @return array<string, mixed>
     */
    protected function inputData(): array
    {
        if ($this->isJson()) {
            $json = $this->json();
            return is_array($json) ? $json : [];
        }

        return $this->post;
    }

    /**
     * @brief Decodes the raw body as JSON.
     *
     * @param bool $associative Decode objects as associative arrays.
     * @return mixed Decoded payload or null on empty/malformed input.
     */
    protected function decodeJson(bool $associative): mixed
    {
        $raw = $this->content();
        if (trim($raw) === '') {
            return null;
        }

        try {
            return json_decode($raw, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @brief Builds the normalized header map from server variables.
     *
     * Handles Apache/CGI quirks where the Authorization header is exposed only as
     * REDIRECT_HTTP_AUTHORIZATION (after mod_rewrite) or through apache_request_headers().
     *
     * @param array<string, mixed> $server Server variables.
     * @return array<string, string>
     */
    protected function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        if (!isset($headers['authorization'])) {
            if (isset($server['REDIRECT_HTTP_AUTHORIZATION']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (function_exists('apache_request_headers') && $server === $_SERVER) {
                foreach ((array)apache_request_headers() as $name => $value) {
                    if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
                        $headers['authorization'] = $value;
                        break;
                    }
                }
            }
        }

        return $headers;
    }
}
