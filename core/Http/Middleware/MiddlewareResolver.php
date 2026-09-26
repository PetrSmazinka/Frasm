<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Config\Config;
use Core\Container\Container;
use Core\Exceptions\CoreException;

/**
 * @file MiddlewareResolver.php
 * @brief Translates middleware specifications (aliases, groups, class names, parameters) into instances.
 */

/**
 * @class MiddlewareResolver
 * @brief Expands middleware specifications declared in config/middleware.php and #[Middleware] attributes.
 *
 * Supported specification forms:
 *  - Group name defined in `middleware.groups` (e.g. 'api'), expanded recursively.
 *  - Alias defined in `middleware.aliases` with optional parameters (e.g. 'throttle:5,60').
 *  - Fully qualified class name with optional parameters (e.g. 'App\Http\Foo:bar').
 *  - A ready MiddlewareInterface instance.
 */
class MiddlewareResolver
{
    /**
     * @var int Maximum nesting depth of middleware groups.
     */
    protected const MAX_GROUP_DEPTH = 10;

    /**
     * @brief MiddlewareResolver constructor.
     *
     * @param Container $container Container used to instantiate middleware classes.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * @brief Resolves a list of specifications into middleware instances.
     *
     * @param list<string|MiddlewareInterface> $specs Middleware specifications.
     * @return list<MiddlewareInterface>
     * @throws CoreException On unknown specifications, invalid classes or group recursion.
     */
    public function resolve(array $specs): array
    {
        $resolved = [];
        foreach ($specs as $spec) {
            foreach ($this->resolveOne($spec, 0) as $middleware) {
                $resolved[] = $middleware;
            }
        }

        return $resolved;
    }

    /**
     * @brief Returns group names whose configured path prefix matches the given request path.
     *
     * Configured under `middleware.paths` as ['/api' => ['api'], ...]; a prefix matches the exact path
     * and any path below it on a segment boundary.
     *
     * @param string $path Normalized request path.
     * @return list<string> Matching middleware specifications.
     */
    public function specsForPath(string $path): array
    {
        $specs = [];
        /** @var array<string, list<string>|string> $paths */
        $paths = (array)Config::get('middleware.paths', []);

        foreach ($paths as $prefix => $pathSpecs) {
            $prefix = '/' . trim((string)$prefix, '/');
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                foreach ((array)$pathSpecs as $spec) {
                    $specs[] = (string)$spec;
                }
            }
        }

        return $specs;
    }

    /**
     * @brief Resolves a single specification (possibly a group) into middleware instances.
     *
     * @param string|MiddlewareInterface $spec Specification.
     * @param int $depth Current group nesting depth.
     * @return list<MiddlewareInterface>
     * @throws CoreException On invalid specification.
     */
    protected function resolveOne(string|MiddlewareInterface $spec, int $depth): array
    {
        if ($spec instanceof MiddlewareInterface) {
            return [$spec];
        }

        if ($depth > self::MAX_GROUP_DEPTH) {
            throw new CoreException("Middleware group nesting exceeds " . self::MAX_GROUP_DEPTH . " levels (recursive group definition?).");
        }

        $groups = (array)Config::get('middleware.groups', []);
        if (isset($groups[$spec])) {
            $result = [];
            foreach ((array)$groups[$spec] as $member) {
                foreach ($this->resolveOne($member, $depth + 1) as $middleware) {
                    $result[] = $middleware;
                }
            }
            return $result;
        }

        [$name, $parameterString] = str_contains($spec, ':') ? explode(':', $spec, 2) : [$spec, null];

        $aliases = (array)Config::get('middleware.aliases', []);
        $class = (string)($aliases[$name] ?? $name);

        if (!class_exists($class) || !is_subclass_of($class, MiddlewareInterface::class)) {
            throw new CoreException("Unknown middleware '{$spec}': not a group, alias, or class implementing MiddlewareInterface.");
        }

        $middleware = $this->container->make($class);

        if ($parameterString !== null) {
            if (!$middleware instanceof ParameterizedMiddlewareInterface) {
                throw new CoreException("Middleware '{$class}' does not accept parameters (given in '{$spec}').");
            }
            // Never mutate a possibly shared instance registered in the container
            $middleware = clone $middleware;
            $middleware->setParameters(array_map('trim', explode(',', $parameterString)));
        }

        return [$middleware];
    }
}
