<?php

declare(strict_types=1);

namespace Core\Routing;

/**
 * @file Route.php
 * @brief Represents a compiled matching rule and its execution target.
 */

/**
 * @class Route
 * @brief Holds URL regex patterns, target handlers, and associated metadata.
 *
 * Known metadata keys: 'auth_roles' (list<string>|null, from #[Authorize]),
 * 'middleware' (list<string>, from #[Middleware]), 'param_types' (cached handler parameter types).
 */
class Route
{
    /**
     * @var string Compiled regular expression for URL matching.
     */
    protected string $regex;

    /**
     * @var list<string> Extracted parameter names from URL placeholders.
     */
    protected array $parameterNames = [];

    /**
     * @var array<string, mixed> Route metadata (e.g. auth policies, roles).
     */
    protected array $metadata = [];

    /**
     * @brief Route constructor.
     *
     * @param string $method HTTP method (GET, POST, etc.).
     * @param string $path URL path pattern (e.g. '/users/{id}').
     * @param callable|array{class-string, string} $handler Action handler.
     * @param array{regex: string, params: list<string>}|null $compiled Precompiled pattern (route cache); null compiles $path.
     * @param string|null $domain Domain name of `app.domains` the route is bound to; null = every application host.
     */
    public function __construct(
        protected string $method,
        protected string $path,
        protected mixed $handler,
        ?array $compiled = null,
        protected ?string $domain = null
    ) {
        if ($compiled === null) {
            $this->compileRegex($path);
        } else {
            $this->regex = $compiled['regex'];
            $this->parameterNames = $compiled['params'];
        }
    }

    /**
     * @brief Returns the HTTP method of the route.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @brief Returns the declared path pattern.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @brief Returns the domain the route is bound to.
     *
     * @return string|null Domain name of `app.domains`, or null when the route answers on every application host.
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * @brief Returns the route handler.
     *
     * @return mixed Callable or [class-string, method].
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * @brief Checks whether the path contains no placeholders (eligible for O(1) lookup).
     *
     * @return bool
     */
    public function isStatic(): bool
    {
        return $this->parameterNames === [];
    }

    /**
     * @brief Returns the normalized path used for static lookups.
     *
     * @return string
     */
    public function getNormalizedPath(): string
    {
        return '/' . trim($this->path, '/');
    }

    /**
     * @brief Exports the compiled route into a var_export()-able array for the route cache.
     *
     * Only routes with [class-string, method] handlers can be exported (closures are not serializable).
     *
     * @return array{method: string, path: string, domain: string|null, handler: array{0: class-string, 1: string}, regex: string, params: list<string>, metadata: array<string, mixed>}
     * @throws \LogicException If the handler is not a [class, method] pair.
     */
    public function toArray(): array
    {
        if (!is_array($this->handler) || !is_string($this->handler[0] ?? null)) {
            throw new \LogicException("Route '{$this->method} {$this->path}' with a closure handler cannot be cached.");
        }

        return [
            'method'   => $this->method,
            'path'     => $this->path,
            'domain'   => $this->domain,
            'handler'  => [$this->handler[0], (string)$this->handler[1]],
            'regex'    => $this->regex,
            'params'   => $this->parameterNames,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @brief Restores a route from its cached array form without recompiling the regex.
     *
     * @param array{method: string, path: string, domain?: string|null, handler: array{0: class-string, 1: string}, regex: string, params: list<string>, metadata: array<string, mixed>} $data Cached route.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $route = new self($data['method'], $data['path'], $data['handler'], [
            'regex'  => $data['regex'],
            'params' => $data['params'],
        ], $data['domain'] ?? null);
        $route->metadata = $data['metadata'];

        return $route;
    }

    /**
     * @brief Returns placeholder names in order of appearance.
     *
     * @return list<string>
     */
    public function getParameterNames(): array
    {
        return $this->parameterNames;
    }

    /**
     * @brief Compiles friendly route paths into named-group regex patterns.
     *
     * Transforms '/users/{id}' into '#^/users/(?P<id>[^/]+)$#D'. Static segments are quoted,
     * so characters such as '.' match literally.
     *
     * @param string $path Raw route pattern.
     * @return void
     */
    protected function compileRegex(string $path): void
    {
        $normalized = '/' . trim($path, '/');

        $parts = preg_split('/(\{[a-zA-Z0-9_]+\})/', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pattern = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/D', $part, $matches)) {
                $this->parameterNames[] = $matches[1];
                $pattern .= '(?P<' . $matches[1] . '>[^/]+)';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }

        $this->regex = '#^' . $pattern . '$#D';
    }

    /**
     * @brief Tests if the given URI matches this route.
     *
     * @param string $uri Request path without query parameters.
     * @return array<string, string>|null Associative array of matched parameters or null.
     */
    public function match(string $uri): ?array
    {
        if (!preg_match($this->regex, $uri, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * @brief Requires authentication (and optionally roles) for a programmatically registered route.
     *
     * @param list<string>|string $roles Required roles; empty means any authenticated identity.
     * @return self
     */
    public function authorize(array|string $roles = []): self
    {
        return $this->setMetadata('auth_roles', array_values((array)$roles));
    }

    /**
     * @brief Appends middleware specifications to the route.
     *
     * @param string ...$specs Middleware specifications (group, alias with parameters, or class name).
     * @return self
     */
    public function middleware(string ...$specs): self
    {
        $current = (array)$this->getMetadata('middleware', []);
        return $this->setMetadata('middleware', array_values(array_merge($current, $specs)));
    }

    /**
     * @brief Sets metadata value on the route.
     *
     * @param string $key Metadata key.
     * @param mixed $value Metadata value.
     * @return self
     */
    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * @brief Retrieves metadata by key.
     *
     * @param string $key Metadata key.
     * @param mixed $default Fallback value.
     * @return mixed
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->metadata) ? $this->metadata[$key] : $default;
    }
}
