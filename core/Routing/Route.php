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
     */
    public function __construct(
        protected string $method,
        protected string $path,
        protected mixed $handler
    ) {
        $this->compileRegex($path);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * @brief Compiles friendly route paths into named-group regex patterns.
     *
     * Transforms '/users/{id}' into '#^/users/(?P<id>[^/]+)$#D'.
     *
     * @param string $path Raw route pattern.
     * @return void
     */
    protected function compileRegex(string $path): void
    {
        $normalized = '/' . trim($path, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function (array $matches): string {
            $this->parameterNames[] = $matches[1];
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $normalized);

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
        return $this->metadata[$key] ?? $default;
    }
}