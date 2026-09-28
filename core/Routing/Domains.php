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
     * @var array<string, string> Literal host => domain name.
     */
    private array $literal = [];

    /**
     * @var list<array{name: string, regex: string, params: list<string>}> Patterns with placeholders, in configuration order.
     */
    private array $patterns = [];

    /**
     * @var array<string, list<string>> Domain name => host patterns (the first one is canonical).
     */
    private array $hosts = [];

    /**
     * @var array<string, list<string>> Domain name => placeholder names.
     */
    private array $parameters = [];

    /**
     * @var array<string, array{name: string, params: array<string, string>}|null> Resolved hosts.
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
     * @brief Finds the domain a host belongs to.
     *
     * @param string $host Host name without port (Request::host()).
     * @return array{name: string, params: array<string, string>}|null Domain name and placeholder values, or null when no pattern matches.
     */
    public function resolve(string $host): ?array
    {
        $host = rtrim(strtolower($host), '.');
        if (array_key_exists($host, $this->resolved)) {
            return $this->resolved[$host];
        }

        $result = null;
        if (isset($this->literal[$host])) {
            $result = ['name' => $this->literal[$host], 'params' => []];
        } elseif ($host !== '' && strlen($host) <= self::MAX_HOST_LENGTH) {
            foreach ($this->patterns as $pattern) {
                if (preg_match($pattern['regex'], $host, $matches)) {
                    $params = [];
                    foreach ($pattern['params'] as $param) {
                        $params[$param] = $matches[$param];
                    }
                    $result = ['name' => $pattern['name'], 'params' => $params];
                    break;
                }
            }
        }

        return $this->resolved[$host] = $result;
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
     * commands, which have no request host); the base path of the application is prepended.
     *
     * @param Request $request Current request.
     * @param string $path Path within the application, optionally with a query string ('/login?next=1').
     * @param string|null $domain Target domain name, or null for the current host.
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
        } elseif ($currentHost === '') {
            throw new CoreException('url() needs a domain outside of an HTTP request.');
        } elseif ($this->isEnabled() && $this->resolve($currentHost) === null) {
            throw new CoreException("The host '{$currentHost}' is not listed in app.domains.");
        } else {
            $host = $currentHost;
        }

        $secure = $currentHost === '' || $request->isSecure();
        $port = $request->port();
        if ($port === ($secure ? 443 : 80)) {
            $port = null;
        }

        return ($secure ? 'https' : 'http') . '://' . $host . ($port !== null ? ":{$port}" : '')
            . $request->basePath() . '/' . ltrim($path, '/');
    }

    /**
     * @brief Compiles and registers one host pattern of a domain.
     *
     * @param string $name Domain name.
     * @param string $pattern Host pattern.
     * @return void
     * @throws CoreException On an invalid or duplicate pattern.
     */
    private function addPattern(string $name, string $pattern): void
    {
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
            if (isset($this->literal[$host])) {
                throw new CoreException("The host '{$host}' is listed in app.domains twice.");
            }
            $this->literal[$host] = $name;
            return;
        }

        $this->patterns[] = ['name' => $name, 'regex' => '#^' . implode('\.', $regex) . '$#D', 'params' => $params];
    }
}
