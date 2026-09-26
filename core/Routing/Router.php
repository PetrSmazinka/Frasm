<?php

declare(strict_types=1);

namespace Core\Routing;

use Closure;
use Core\Container\Container;
use Core\Exceptions\CoreException;
use Core\Exceptions\HttpResponseException;
use Core\Exceptions\MethodNotAllowedException;
use Core\Exceptions\RouteNotFoundException;
use Core\Http\CallableHandler;
use Core\Http\Middleware\AuthorizeMiddleware;
use Core\Http\Middleware\MiddlewareResolver;
use Core\Http\Middleware\Pipeline;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Attributes\Authorize;
use Core\Routing\Attributes\Middleware as MiddlewareAttribute;
use Core\Routing\Attributes\Route as RouteAttribute;
use JsonSerializable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Stringable;
use Throwable;

/**
 * @file Router.php
 * @brief Central HTTP request router with PHP 8 attribute scanning support.
 */

/**
 * @class Router
 * @brief Manages route registration, controller reflection, route middleware and URL dispatching.
 *
 * Controllers and closures are invoked through the DI container, so constructor and action
 * parameters are autowired; route placeholders are injected by parameter name and coerced to the
 * declared scalar type (an uncoercible value such as 'abc' for `int $id` yields 404).
 */
class Router
{
    /**
     * @var list<Route> Registered route instances in registration order.
     */
    protected array $routes = [];

    /**
     * @var array<string, array<string, Route>> Placeholder-free routes indexed by method and normalized path.
     */
    protected array $staticRoutes = [];

    /**
     * @var list<Route> Routes with placeholders, matched by regex in registration order.
     */
    protected array $dynamicRoutes = [];

    /**
     * @var Container Container used to instantiate controllers and middleware.
     */
    protected Container $container;

    /**
     * @brief Router constructor.
     *
     * @param Container|null $container DI container (defaults to the global instance).
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
    }

    /**
     * @brief Registers a new route.
     *
     * @param string $method HTTP method.
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @return Route Created route instance.
     */
    public function addRoute(string $method, string $path, mixed $handler): Route
    {
        $route = new Route(strtoupper($method), $path, $handler);
        $this->indexRoute($route);
        return $route;
    }

    /**
     * @brief Exports all cacheable routes (those with [class, method] handlers).
     *
     * @return list<array<string, mixed>> Route arrays produced by Route::toArray().
     */
    public function exportRoutes(): array
    {
        $exported = [];
        foreach ($this->routes as $route) {
            $handler = $route->getHandler();
            if (is_array($handler) && is_string($handler[0] ?? null)) {
                $exported[] = $route->toArray();
            }
        }

        return $exported;
    }

    /**
     * @brief Registers routes restored from the route cache.
     *
     * @param list<array<string, mixed>> $routes Route arrays produced by exportRoutes().
     * @return void
     */
    public function importRoutes(array $routes): void
    {
        foreach ($routes as $data) {
            $this->indexRoute(Route::fromArray($data));
        }
    }

    /**
     * @brief Adds a route to the ordered list and to the static or dynamic index.
     *
     * @param Route $route Route to index.
     * @return void
     */
    protected function indexRoute(Route $route): void
    {
        $this->routes[] = $route;

        if ($route->isStatic()) {
            // First registration wins, consistent with ordered matching
            $this->staticRoutes[$route->getMethod()][$route->getNormalizedPath()] ??= $route;
        } else {
            $this->dynamicRoutes[] = $route;
        }
    }

    /**
     * @brief Registers a GET route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @return Route
     */
    public function get(string $path, mixed $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * @brief Registers a POST route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @return Route
     */
    public function post(string $path, mixed $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * @brief Registers a PUT route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @return Route
     */
    public function put(string $path, mixed $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * @brief Registers a PATCH route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @return Route
     */
    public function patch(string $path, mixed $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * @brief Registers a DELETE route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @return Route
     */
    public function delete(string $path, mixed $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * @brief Scans a controller class using PHP Reflection and registers attributed routes.
     *
     * Reads #[Route]/#[Get]/#[Post]/#[Put]/#[Patch]/#[Delete], #[Authorize] and #[Middleware].
     * Only attributes of these types are instantiated, so foreign attributes are ignored.
     *
     * @param class-string $controllerClass Fully qualified class name.
     * @return void
     * @throws CoreException If class does not exist.
     */
    public function registerController(string $controllerClass): void
    {
        if (!class_exists($controllerClass)) {
            throw new CoreException("Controller class '{$controllerClass}' does not exist.");
        }

        $refClass = new ReflectionClass($controllerClass);
        if ($refClass->isAbstract()) {
            return;
        }

        // Class-level path prefix
        $basePath = '';
        $classRoutes = $refClass->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($classRoutes !== []) {
            $basePath = trim($classRoutes[0]->newInstance()->path, '/');
        }

        // Class-level authorization and middleware
        $classAuthorize = $refClass->getAttributes(Authorize::class);
        $classRoles = $classAuthorize !== [] ? (array)$classAuthorize[0]->newInstance()->roles : null;
        $classMiddleware = $this->collectMiddleware($refClass->getAttributes(MiddlewareAttribute::class));

        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $routeAttributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($routeAttributes === []) {
                continue;
            }

            // Method-level authorization overrides class-level
            $methodAuthorize = $method->getAttributes(Authorize::class);
            $methodRoles = $methodAuthorize !== [] ? (array)$methodAuthorize[0]->newInstance()->roles : $classRoles;

            $middleware = array_merge(
                $classMiddleware,
                $this->collectMiddleware($method->getAttributes(MiddlewareAttribute::class))
            );

            foreach ($routeAttributes as $attribute) {
                /** @var RouteAttribute $instance */
                $instance = $attribute->newInstance();
                $methodPath = trim($instance->path, '/');

                $segments = array_filter([$basePath, $methodPath], fn(string $s): bool => $s !== '');
                $fullPath = '/' . implode('/', $segments);

                $route = $this->addRoute($instance->method, $fullPath, [$controllerClass, $method->getName()]);

                if ($methodRoles !== null) {
                    $route->setMetadata('auth_roles', array_values($methodRoles));
                }
                if ($middleware !== []) {
                    $route->setMetadata('middleware', $middleware);
                }
                if (!$route->isStatic()) {
                    // Resolved now so cached routes never need reflection at dispatch time
                    $route->setMetadata('param_types', $this->reflectParameterTypes($route->getHandler()));
                }
            }
        }
    }

    /**
     * @brief Recursively scans a directory for Controller classes and registers their attributed routes.
     *
     * @param string $directoryPath Absolute filesystem path to the controllers directory.
     * @param string $baseNamespace Root namespace mapped to this directory.
     * @return void
     */
    public function registerControllersFromDirectory(string $directoryPath, string $baseNamespace = 'App\\Controllers\\'): void
    {
        foreach ($this->discoverControllers($directoryPath, $baseNamespace) as $class => $file) {
            // Load file explicitly if not yet registered in runtime
            if (!class_exists($class, false)) {
                require_once $file;
            }

            if (class_exists($class, false)) {
                $this->registerController($class);
            }
        }
    }

    /**
     * @brief Lists controller classes (files ending with 'Controller.php') below a directory.
     *
     * The namespace suffix is derived from the directory nesting
     * (Controllers/Admin/DashboardController.php → <base>Admin\DashboardController).
     *
     * @param string $directoryPath Absolute filesystem path to the controllers directory.
     * @param string $baseNamespace Root namespace mapped to this directory.
     * @return array<string, string> Fully qualified class name => absolute file path, sorted by path.
     */
    public function discoverControllers(string $directoryPath, string $baseNamespace = 'App\\Controllers\\'): array
    {
        $realDirectoryPath = realpath($directoryPath);
        if ($realDirectoryPath === false || !is_dir($realDirectoryPath)) {
            return [];
        }

        $baseNamespace = rtrim($baseNamespace, '\\') . '\\';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDirectoryPath, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Controller.php')) {
                $files[] = $file->getPathname();
            }
        }

        // Deterministic registration order regardless of filesystem ordering
        sort($files, SORT_STRING);

        $controllers = [];
        foreach ($files as $file) {
            $subPath = trim(substr(dirname($file), strlen($realDirectoryPath)), DIRECTORY_SEPARATOR);
            $subNamespace = $subPath !== '' ? str_replace(DIRECTORY_SEPARATOR, '\\', $subPath) . '\\' : '';
            $controllers[$baseNamespace . $subNamespace . basename($file, '.php')] = $file;
        }

        return $controllers;
    }

    /**
     * @brief Finds the route matching the request.
     *
     * Static routes (no placeholders) are resolved by an O(1) lookup and take precedence over
     * placeholder routes, which are tried in registration order. HEAD requests fall back to GET routes.
     *
     * @param Request $request Incoming request.
     * @return array{0: Route, 1: array<string, string>} Matched route and its raw placeholder values.
     * @throws RouteNotFoundException If no route pattern matches the path.
     * @throws MethodNotAllowedException If the path matches only routes of other methods.
     */
    public function match(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();

        if (isset($this->staticRoutes[$method][$path])) {
            return [$this->staticRoutes[$method][$path], []];
        }

        $allowed = [];
        $getFallback = null;

        foreach ($this->dynamicRoutes as $route) {
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }

            $routeMethod = $route->getMethod();
            if ($routeMethod === $method) {
                return [$route, $params];
            }

            if ($routeMethod === 'GET' && $getFallback === null) {
                $getFallback = [$route, $params];
            }

            $allowed[$routeMethod] = true;
        }

        if ($method === 'HEAD') {
            if (isset($this->staticRoutes['GET'][$path])) {
                return [$this->staticRoutes['GET'][$path], []];
            }
            if ($getFallback !== null) {
                return $getFallback;
            }
        }

        foreach ($this->staticRoutes as $routeMethod => $paths) {
            if (isset($paths[$path])) {
                $allowed[$routeMethod] = true;
            }
        }

        if ($allowed !== []) {
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }

            throw new MethodNotAllowedException(
                "Method {$method} not allowed for path: {$path}",
                405,
                null,
                array_keys($allowed)
            );
        }

        throw new RouteNotFoundException("No route found for path: {$path}", 404);
    }

    /**
     * @brief Dispatches the request: matches a route, runs its middleware and invokes the handler.
     *
     * Route middleware order: path groups (`middleware.paths`), AuthorizeMiddleware (when the route
     * carries #[Authorize]), class-level #[Middleware], method-level #[Middleware].
     * The matched Route and its parameters are exposed as request attributes 'route' and 'route_params'.
     *
     * @param Request $request Incoming request.
     * @param (Closure(Throwable, Request): Response)|null $exceptionRenderer Converts exceptions inside the route pipeline to responses.
     * @return Response
     * @throws RouteNotFoundException If no route matches.
     * @throws MethodNotAllowedException If the method is not allowed.
     */
    public function dispatch(Request $request, ?Closure $exceptionRenderer = null): Response
    {
        [$route, $params] = $this->match($request);

        $request->setAttribute('route', $route)->setAttribute('route_params', $params);

        /** @var MiddlewareResolver $resolver */
        $resolver = $this->container->get(MiddlewareResolver::class);

        $specs = $resolver->specsForPath($request->path());
        if ($route->getMetadata('auth_roles') !== null) {
            $specs[] = AuthorizeMiddleware::class;
        }
        foreach ((array)$route->getMetadata('middleware', []) as $spec) {
            $specs[] = (string)$spec;
        }

        $core = new CallableHandler(fn(Request $r): Response => $this->runRoute($route, $params, $r));

        return (new Pipeline($resolver->resolve($specs), $core, $exceptionRenderer))->handle($request);
    }

    /**
     * @brief Returns all currently registered route instances.
     *
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * @brief Converts a handler return value into a Response.
     *
     * Response → as is; string/Stringable/scalar → HTML; array/object → JSON; null → empty 200.
     *
     * @param mixed $result Handler return value.
     * @return Response
     * @throws \JsonException If the value cannot be JSON encoded.
     */
    public static function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result === null => new Response(''),
            is_string($result), $result instanceof Stringable => Response::html((string)$result),
            is_array($result), $result instanceof JsonSerializable, is_object($result) => Response::json($result),
            is_bool($result) => Response::html($result ? '1' : ''),
            default => Response::html((string)$result),
        };
    }

    /**
     * @brief Invokes the route handler and normalizes its result.
     *
     * @param Route $route Matched route.
     * @param array<string, string> $params Raw placeholder values.
     * @param Request $request Current request.
     * @return Response
     * @throws CoreException If the handler is invalid.
     * @throws RouteNotFoundException If a placeholder cannot be coerced to the declared type.
     */
    protected function runRoute(Route $route, array $params, Request $request): Response
    {
        $this->container->instance(Request::class, $request);

        try {
            $handler = $route->getHandler();
            $arguments = $this->coerceParameters($route, $params);

            if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
                [$class, $action] = $handler;

                if (!class_exists($class)) {
                    throw new CoreException("Controller class '{$class}' not found.", 500);
                }
                if (!method_exists($class, (string)$action)) {
                    throw new CoreException("Action '{$action}' not found in '{$class}'.", 500);
                }

                $result = $this->container->call([$this->container->make($class), (string)$action], $arguments);
            } elseif (is_callable($handler)) {
                $result = $this->container->call($handler, $arguments);
            } else {
                throw new CoreException("Invalid route handler format.", 500);
            }
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return self::toResponse($result);
    }

    /**
     * @brief Coerces placeholder values to the scalar types declared by the handler parameters.
     *
     * @param Route $route Matched route (parameter types are cached in its metadata).
     * @param array<string, string> $params Raw placeholder values.
     * @return array<string, mixed>
     * @throws RouteNotFoundException If a value does not represent the declared type.
     */
    protected function coerceParameters(Route $route, array $params): array
    {
        if ($params === []) {
            return [];
        }

        /** @var array<string, string>|null $types */
        $types = $route->getMetadata('param_types');
        if ($types === null) {
            $types = $this->reflectParameterTypes($route->getHandler());
            $route->setMetadata('param_types', $types);
        }

        foreach ($params as $name => $value) {
            $type = $types[$name] ?? null;
            $coerced = match ($type) {
                'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
                'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                default => $value,
            };

            if ($coerced === null) {
                throw new RouteNotFoundException("Route parameter '{$name}' is not a valid {$type}.", 404);
            }

            $params[$name] = $coerced;
        }

        return $params;
    }

    /**
     * @brief Reads single builtin scalar types of the handler parameters.
     *
     * @param mixed $handler Route handler.
     * @return array<string, string> Parameter name => 'int'|'float'|'bool'.
     */
    protected function reflectParameterTypes(mixed $handler): array
    {
        try {
            if (is_array($handler) && count($handler) === 2) {
                $reflection = new ReflectionMethod($handler[0], (string)$handler[1]);
            } elseif ($handler instanceof Closure || (is_string($handler) && function_exists($handler))) {
                $reflection = new ReflectionFunction($handler);
            } elseif (is_object($handler) && method_exists($handler, '__invoke')) {
                $reflection = new ReflectionMethod($handler, '__invoke');
            } else {
                return [];
            }
        } catch (\ReflectionException) {
            return [];
        }

        $types = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['int', 'float', 'bool'], true)) {
                $types[$parameter->getName()] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * @brief Flattens #[Middleware] attributes into a list of specifications.
     *
     * @param list<ReflectionAttribute<MiddlewareAttribute>> $attributes Reflected attributes.
     * @return list<string>
     */
    protected function collectMiddleware(array $attributes): array
    {
        $specs = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->newInstance()->middleware as $spec) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }
}
