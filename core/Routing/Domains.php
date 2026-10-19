<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Exceptions\CoreException;
use Core\Http\Request;

/**
 * @file Domains.php
 * @brief Named host names (domains and subdomains) the application answers to.
 */

/**
 * @class Domains
 * @brief Resolves the request host to a named domain of `app.domains` and builds absolute URLs.
 *
 * Every name maps to one or more host patterns, the first of which is canonical (used for URLs).
 * A pattern consists of literal DNS labels and placeholders that stand for one whole label
 * ('{tenant}.example.com'); their values are passed to actions bound to the domain like route
 * parameters. Literal hosts are resolved by an O(1) lookup and take precedence over patterns, which
 * are tried in configuration order, so 'www.example.com' may be listed next to '{tenant}.example.com'.
 *
 * A pattern may end with a path ('app.example.com/songs'): the domain is then a section of the host.
 * Its routes are registered below that path, url() puts the path in front, and a request belongs to
 * the domain of its host whose path is the longest prefix of the request path (whole segments), so
 * 'app.example.com' (no path) takes what no section of the host claims. Several names may share a
 * host this way, each with a different path.
 *
 * With an empty `app.domains` every host is accepted. Otherwise a host matching no pattern resolves
 * to null: the router answers 404 and url() refuses it, so a forged Host header neither reaches the
 * application nor leaks into generated links.
 */
final class Domains
{
    /**
     * @var string Valid domain name.
     */
    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,39}$/D';

    /**
     * @var string Path of a domain: literal segments of letters, digits, '.', '_' and '-'.
     */
    private const PATH = '#^(/[a-z0-9._-]+)+$#D';

    /**
     * @var string One DNS label (a placeholder value), without delimiters.
     */
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * @var string Placeholder within a host pattern, without delimiters.
     */
    private const PLACEHOLDER = '\{([a-zA-Z_][a-zA-Z0-9_]*)\}';

    /**
     * @var int Maximum length of a host name.
     */
    private const MAX_HOST_LENGTH = 253;

    /**
     * @var array<string, array<string, string>> Literal host => path => domain name ('' = the whole host).
     */
    private array $literal = [];

    /**
     * @var list<array{name: string, regex: string, params: list<string>, path: string}> Patterns with placeholders, in configuration order.
     */
    private array $patterns = [];

    /**
     * @var array<string, string> Domain name => its path ('' = the whole host), the same for all its hosts.
     */
    private array $paths = [];

    /**
     * @var array<string, list<string>> Domain name => host patterns (the first one is canonical).
     */
    private array $hosts = [];

    /**
     * @var array<string, list<string>> Domain name => placeholder names.
     */
    private array $parameters = [];

    /**
     * @var array<string, array{name: string, params: array<string, string>}|null> Resolved hosts and paths.
     */
    private array $resolved = [];

    /**
     * @brief Domains constructor.
     *
     * @param array<mixed> $config `app.domains`: name => host pattern or list of host patterns.
     * @throws CoreException On an invalid name or host pattern.
     */
    public function __construct(array $config)
    {
        foreach ($config as $name => $patterns) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new CoreException("Invalid domain name '{$name}' in app.domains (lowercase letters, digits, '-' and '_').");
            }

            $patterns = is_array($patterns) ? array_values($patterns) : [$patterns];
            if ($patterns === []) {
                throw new CoreException("Domain '{$name}' in app.domains has no host.");
            }

            foreach ($patterns as $pattern) {
                if (!is_string($pattern)) {
                    throw new CoreException("Hosts of domain '{$name}' in app.domains must be strings.");
                }
                $this->addPattern($name, trim($pattern));
            }
        }
    }

    /**
     * @brief Checks whether any domain is configured (the host is then validated).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->hosts !== [];
    }

    /**
     * @brief Checks whether a domain name is configured.
     *
     * @param string $name Domain name.
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->hosts[$name]);
    }

    /**
     * @brief Returns the configured domain names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->hosts);
    }

    /**
     * @brief Returns the placeholder names of a domain ('{tenant}.example.com' → ['tenant']).
     *
     * @param string $name Domain name.
     * @return list<string> Empty for unknown domains and literal hosts.
     */
    public function parameterNames(string $name): array
    {
        return $this->parameters[$name] ?? [];
    }

    /**
     * @brief Returns the path of a domain ('/songs' for 'app.example.com/songs').
     *
     * @param string $name Domain name.
     * @return string '' when the domain is the whole host (or unknown).
     */
    public function path(string $name): string
    {
        return $this->paths[$name] ?? '';
    }

    /**
     * @brief Finds the domain a host (and a path on it) belongs to.
     *
     * Of the domains of the host, the one whose path is the longest prefix of the request path wins
     * (whole segments: '/songs' covers '/songs' and '/songs/12', not '/songsx'). Without a path, the
     * domain of the whole host is preferred (otherwise the first one listed for the host).
     *
     * @param string $host Host name without port (Request::host()).
     * @param string|null $path Request path (Request::path()), or null for the host alone.
     * @return array{name: string, params: array<string, string>}|null Domain name and placeholder values, or null when no pattern matches.
     */
    public function resolve(string $host, ?string $path = null): ?array
    {
        $host = rtrim(strtolower($host), '.');
        $key = $host . '|' . ($path ?? "\0");
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $result = null;
        if (isset($this->literal[$host])) {
            $name = $this->pick($this->literal[$host], $path);
            $result = $name === null ? null : ['name' => $name, 'params' => []];
        } elseif ($host !== '' && strlen($host) <= self::MAX_HOST_LENGTH) {
            // Sections of the host: path => [name, placeholder values]; the first pattern of a path wins
            $sections = [];
            foreach ($this->patterns as $pattern) {
                if (!isset($sections[$pattern['path']]) && preg_match($pattern['regex'], $host, $matches)) {
                    $params = [];
                    foreach ($pattern['params'] as $param) {
                        $params[$param] = $matches[$param];
                    }
                    $sections[$pattern['path']] = ['name' => $pattern['name'], 'params' => $params];
                }
            }
            $chosen = $sections === [] ? null : $this->pick(array_map(fn(array $section): string => $section['name'], $sections), $path);
            if ($chosen !== null) {
                foreach ($sections as $section) {
                    if ($section['name'] === $chosen) {
                        $result = $section;
                        break;
                    }
                }
            }
        }

        if (count($this->resolved) > 1000) {
            $this->resolved = [];
        }

        return $this->resolved[$key] = $result;
    }

    /**
     * @brief Chooses the domain of a host by the request path.
     *
     * @param array<string, string> $sections Path => domain name ('' = the whole host).
     * @param string|null $path Request path, or null for the host alone.
     * @return string|null
     */
    private function pick(array $sections, ?string $path): ?string
    {
        if ($path === null) {
            return $sections[''] ?? $sections[array_key_first($sections)];
        }
        $best = null;
        $length = -1;
        foreach ($sections as $prefix => $name) {
            $prefix = (string)$prefix;
            if (strlen($prefix) > $length && ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/'))) {
                $best = $name;
                $length = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * @brief Returns the canonical host of a domain with its placeholders filled in.
     *
     * Missing placeholder values are taken over from the current request when it belongs to a domain
     * with the same placeholder (a link from one tenant's page stays within that tenant).
     *
     * @param string $name Domain name.
     * @param array<string, string|int> $params Placeholder values.
     * @param Request|null $request Current request supplying missing values.
     * @return string Host name.
     * @throws CoreException On an unknown domain, or a missing or invalid placeholder value.
     */
    public function host(string $name, array $params = [], ?Request $request = null): string
    {
        if (!isset($this->hosts[$name])) {
            throw new CoreException("Unknown domain '{$name}' (define it in app.domains).");
        }

        $current = $request !== null ? ($this->resolve($request->host())['params'] ?? []) : [];

        return (string)preg_replace_callback('/' . self::PLACEHOLDER . '/', function (array $matches) use ($name, $params, $current): string {
            $value = $params[$matches[1]] ?? $current[$matches[1]] ?? null;
            if ($value === null) {
                throw new CoreException("Missing value of {{$matches[1]}} for a URL of domain '{$name}'.");
            }

            $value = strtolower((string)$value);
            if (!preg_match('/^' . self::LABEL . '$/D', $value)) {
                throw new CoreException("Invalid value '{$value}' of {{$matches[1]}}: a host name label (letters, digits and '-').");
            }

            return $value;
        }, $this->hosts[$name][0]);
    }

    /**
     * @brief Builds an absolute URL on the current host or on another domain.
     *
     * The scheme and an explicit port are taken over from the current request (HTTPS for console
     * commands, which have no request host); the base path of the application is prepended, and the
     * path of the domain (the target one, or without a target the domain of the current request).
     *
     * @param Request $request Current request.
     * @param string $path Path within the application (within the domain's path), optionally with a query string ('/login?next=1').
     * @param string|null $domain Target domain name, or null for the current host (and its section).
     * @param array<string, string|int> $params Placeholder values of the target domain.
     * @return string Absolute URL.
     * @throws CoreException When the path is not a local path, the current host is not an application
     *                       host, or the domain cannot be resolved to a host.
     */
    public function url(Request $request, string $path = '/', ?string $domain = null, array $params = []): string
    {
        if (str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
            throw new CoreException("url() expects a path within the application, not '{$path}'.");
        }

        $currentHost = $request->host();
        if ($domain !== null) {
            $host = $this->host($domain, $params, $request);
            $section = $this->path($domain);
        } elseif ($currentHost === '') {
            throw new CoreException('url() needs a domain outside of an HTTP request.');
        } elseif ($this->isEnabled() && ($current = $this->resolve($currentHost, $request->path())) === null) {
            throw new CoreException("The host '{$currentHost}' is not listed in app.domains.");
        } else {
            $host = $currentHost;
            $section = isset($current) ? $this->path($current['name']) : '';
        }

        $secure = $currentHost === '' || $request->isSecure();
        $port = $request->port();
        if ($port === ($secure ? 443 : 80)) {
            $port = null;
        }

        // The root of a section is its path itself ('/songs', not '/songs/'), also before a query or fragment
        $local = '/' . ltrim($path, '/');
        if ($section !== '' && ($local === '/' || $local[1] === '?' || $local[1] === '#')) {
            $local = substr($local, 1);
        }

        return ($secure ? 'https' : 'http') . '://' . $host . ($port !== null ? ":{$port}" : '')
            . $request->basePath() . $section . $local;
    }

    /**
     * @brief Compiles and registers one host pattern of a domain.
     *
     * @param string $name Domain name.
     * @param string $pattern Host pattern, optionally followed by the path of the domain.
     * @return void
     * @throws CoreException On an invalid or duplicate pattern.
     */
    private function addPattern(string $name, string $pattern): void
    {
        $slash = strpos($pattern, '/');
        $path = $slash === false ? '' : rtrim(strtolower(substr($pattern, $slash)), '/');
        $pattern = $slash === false ? $pattern : substr($pattern, 0, $slash);
        if ($path !== '' && !preg_match(self::PATH, $path)) {
            throw new CoreException("Invalid path '{$path}' of domain '{$name}': literal segments of letters, digits, '.', '_' and '-'.");
        }
        if (isset($this->paths[$name]) && $this->paths[$name] !== $path) {
            throw new CoreException("All hosts of domain '{$name}' must have the same path.");
        }
        $this->paths[$name] = $path;

        $labels = [];
        $regex = [];
        $params = [];

        foreach (explode('.', $pattern) as $label) {
            if (preg_match('/^' . self::PLACEHOLDER . '$/D', $label, $matches)) {
                if (in_array($matches[1], $params, true)) {
                    throw new CoreException("Host pattern '{$pattern}' of domain '{$name}' repeats the placeholder {{$matches[1]}}.");
                }
                $params[] = $matches[1];
                $labels[] = $label;
                $regex[] = '(?P<' . $matches[1] . '>' . self::LABEL . ')';
                continue;
            }

            $label = strtolower($label);
            if (!preg_match('/^' . self::LABEL . '$/D', $label)) {
                throw new CoreException("Invalid host '{$pattern}' of domain '{$name}': labels consist of letters, digits and '-', or are a placeholder such as {tenant}.");
            }
            $labels[] = $label;
            $regex[] = preg_quote($label, '#');
        }

        $host = implode('.', $labels);

        $sorted = $params;
        sort($sorted);
        if (isset($this->parameters[$name])) {
            $known = $this->parameters[$name];
            sort($known);
            if ($known !== $sorted) {
                throw new CoreException("All hosts of domain '{$name}' must use the same placeholders.");
            }
        }
        $this->parameters[$name] ??= $params;
        $this->hosts[$name][] = $host;

        if ($params === []) {
            if (isset($this->literal[$host][$path])) {
                throw new CoreException("The host '{$host}" . ($path !== '' ? $path : '') . "' is listed in app.domains twice.");
            }
            $this->literal[$host][$path] = $name;
            return;
        }

        $this->patterns[] = ['name' => $name, 'regex' => '#^' . implode('\.', $regex) . '$#D', 'params' => $params, 'path' => $path];
    }
}
