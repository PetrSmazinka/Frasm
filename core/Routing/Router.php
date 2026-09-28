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
use Core\Http\Middleware\TaskLockMiddleware;
use Core\Http\Middleware\MiddlewareResolver;
use Core\Http\Middleware\Pipeline;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Attributes\Authorize;
use Core\Routing\Attributes\Domain;
use Core\Routing\Attributes\Middleware as MiddlewareAttribute;
use Core\Routing\Attributes\Route as RouteAttribute;
use Core\Scheduling\Schedule;
use Core\Scheduling\ScheduledTask;
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
 *
 * Routes may be bound to a named domain of `app.domains` (#[Domain]); placeholders of the host
 * ('{tenant}.example.com') are injected like path placeholders. Routes of the request's domain are
 * matched before unbound routes, which answer on every application host.
 */
class Router
{
    /**
     * @var list<Route> Registered route instances in registration order.
     */
    protected array $routes = [];

    /**
     * @var array<string, array<string, array<string, Route>>> Placeholder-free routes indexed by domain
     *      ('' = unbound), method and normalized path.
     */
    protected array $staticRoutes = [];

    /**
     * @var array<string, list<Route>> Routes with placeholders by domain ('' = unbound), matched by regex in registration order.
     */
    protected array $dynamicRoutes = [];

    /**
     * @var Domains|null Named application hosts (resolved lazily from the container).
     */
    protected ?Domains $domains = null;

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
     * @param string|null $domain Domain name of `app.domains` the route is bound to; null = every application host.
     * @return Route Created route instance.
     * @throws CoreException If the domain is unknown or shares a placeholder name with the path.
     */
    public function addRoute(string $method, string $path, mixed $handler, ?string $domain = null): Route
    {
        $route = new Route(strtoupper($method), $path, $handler, null, $domain);
        if ($domain !== null) {
            $this->assertDomain($route);
        }
        $this->indexRoute($route);
        return $route;
    }

    /**
     * @brief Verifies that a bound route refers to a configured domain and that its placeholders are unique.
     *
     * @param Route $route Route bound to a domain.
     * @return void
     * @throws CoreException If the domain is unknown or a placeholder name is used by both the host and the path.
     */
    protected function assertDomain(Route $route): void
    {
        $domain = (string)$route->getDomain();
        $label = "Route '{$route->getMethod()} {$route->getPath()}'";

        if (!$this->domains()->has($domain)) {
            throw new CoreException("{$label} is bound to the unknown domain '{$domain}' (define it in app.domains).");
        }

        $shared = array_intersect($route->getParameterNames(), $this->domains()->parameterNames($domain));
        if ($shared !== []) {
            throw new CoreException("{$label} uses the placeholder {" . reset($shared) . "} in both its host and its path.");
        }
    }

    /**
     * @brief Returns the named application hosts.
     *
     * @return Domains
     */
    protected function domains(): Domains
    {
        return $this->domains ??= $this->container->get(Domains::class);
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
        $domain = $route->getDomain() ?? '';

        if ($route->isStatic()) {
            // First registration wins, consistent with ordered matching
            $this->staticRoutes[$domain][$route->getMethod()][$route->getNormalizedPath()] ??= $route;
        } else {
            $this->dynamicRoutes[$domain][] = $route;
        }
    }

    /**
     * @brief Registers a GET route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @param string|null $domain Domain name of `app.domains`; null = every application host.
     * @return Route
     */
    public function get(string $path, mixed $handler, ?string $domain = null): Route
    {
        return $this->addRoute('GET', $path, $handler, $domain);
    }

    /**
     * @brief Registers a POST route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @param string|null $domain Domain name of `app.domains`; null = every application host.
     * @return Route
     */
    public function post(string $path, mixed $handler, ?string $domain = null): Route
    {
        return $this->addRoute('POST', $path, $handler, $domain);
    }

    /**
     * @brief Registers a PUT route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @param string|null $domain Domain name of `app.domains`; null = every application host.
     * @return Route
     */
    public function put(string $path, mixed $handler, ?string $domain = null): Route
    {
        return $this->addRoute('PUT', $path, $handler, $domain);
    }

    /**
     * @brief Registers a PATCH route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @param string|null $domain Domain name of `app.domains`; null = every application host.
     * @return Route
     */
    public function patch(string $path, mixed $handler, ?string $domain = null): Route
    {
        return $this->addRoute('PATCH', $path, $handler, $domain);
    }

    /**
     * @brief Registers a DELETE route.
     *
     * @param string $path URL path pattern.
     * @param callable|array{class-string, string} $handler Action handler.
     * @param string|null $domain Domain name of `app.domains`; null = every application host.
     * @return Route
     */
    public function delete(string $path, mixed $handler, ?string $domain = null): Route
    {
        return $this->addRoute('DELETE', $path, $handler, $domain);
    }

    /**
     * @brief Scans a controller class using PHP Reflection and registers attributed routes.
     *
     * Reads #[Route]/#[Get]/#[Post]/#[Put]/#[Patch]/#[Delete], #[Domain], #[Authorize] and #[Middleware].
     * Only attributes of these types are instantiated, so foreign attributes are ignored.
     *
     * @param class-string $controllerClass Fully qualified class name.
     * @return void
     * @throws CoreException If class does not exist or a route is bound to an unknown domain.
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

        // Class-level domain, authorization and middleware
        $classDomain = $refClass->getAttributes(Domain::class);
        $classDomain = $classDomain !== [] ? $classDomain[0]->newInstance()->name : null;
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

            // Method-level domain and authorization override class-level
            $methodDomain = $method->getAttributes(Domain::class);
            $domain = $methodDomain !== [] ? $methodDomain[0]->newInstance()->name : $classDomain;
            $hostParams = $domain !== null ? $this->domains()->parameterNames($domain) : [];

            $methodAuthorize = $method->getAttributes(Authorize::class);
            $methodRoles = $methodAuthorize !== [] ? (array)$methodAuthorize[0]->newInstance()->roles : $classRoles;

            $middleware = array_merge(
                $classMiddleware,
                $this->collectMiddleware($method->getAttributes(MiddlewareAttribute::class))
            );

            // A scheduled action shares its lock with the scheduler (see TaskLockMiddleware)
            $scheduleAttributes = $method->getAttributes(Schedule::class);
            if ($scheduleAttributes !== []) {
                $middleware[] = TaskLockMiddleware::class . ':' . ScheduledTask::keyFor($controllerClass, $method->getName())
                    . ',' . $scheduleAttributes[0]->newInstance()->timeout;
            }

            foreach ($routeAttributes as $attribute) {
                /** @var RouteAttribute $instance */
                $instance = $attribute->newInstance();
                $methodPath = trim($instance->path, '/');

                $segments = array_filter([$basePath, $methodPath], fn(string $s): bool => $s !== '');
                $fullPath = '/' . implode('/', $segments);

                $route = $this->addRoute($instance->method, $fullPath, [$controllerClass, $method->getName()], $domain);

                if ($methodRoles !== null) {
                    $route->setMetadata('auth_roles', array_values($methodRoles));
                }
                if ($middleware !== []) {
                    $route->setMetadata('middleware', $middleware);
                }
                if (!$route->isStatic() || $hostParams !== []) {
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
     * With `app.domains` configured, the host is resolved first: an unknown host yields 404, and the
     * request attributes 'domain' (name or null) and 'domain_params' (host placeholder values) are set,
     * also for error pages.
     *
     * Static routes (no placeholders) are resolved by an O(1) lookup and take precedence over
     * placeholder routes, also across groups, so a global '/login' is not shadowed by a domain's
     * '/{slug}'. Within each kind the routes of the request's domain come before unbound routes;
     * placeholder routes are tried in registration order. HEAD requests fall back to GET routes.
     *
     * @param Request $request Incoming request.
     * @return array{0: Route, 1: array<string, string>} Matched route and its raw placeholder values (host and path).
     * @throws RouteNotFoundException If the host is unknown or no route pattern matches the path.
     * @throws MethodNotAllowedException If the path matches only routes of other methods.
     */
    public function match(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();

        $resolved = null;
        if ($this->domains()->isEnabled()) {
            $resolved = $this->domains()->resolve($request->host());
            if ($resolved === null) {
                throw new RouteNotFoundException("The host '{$request->host()}' is not listed in app.domains.", 404);
            }
        }
        $request->setAttribute('domain', $resolved['name'] ?? null)
            ->setAttribute('domain_params', $resolved['params'] ?? []);

        // Groups in order of precedence: the host's domain (with its placeholder values), then unbound routes
        $groups = [['', []]];
        if ($resolved !== null) {
            array_unshift($groups, [$resolved['name'], $resolved['params']]);
        }

        foreach ($groups as [$group, $hostParams]) {
            if (isset($this->staticRoutes[$group][$method][$path])) {
                return [$this->staticRoutes[$group][$method][$path], $hostParams];
            }
        }

        $allowed = [];
        $getFallback = null;

        foreach ($groups as [$group, $hostParams]) {
            foreach ($this->dynamicRoutes[$group] ?? [] as $route) {
                $params = $route->match($path);
                if ($params === null) {
                    continue;
                }

                $routeMethod = $route->getMethod();
                if ($routeMethod === $method) {
                    return [$route, $hostParams + $params];
                }

                if ($routeMethod === 'GET' && $getFallback === null) {
                    $getFallback = [$route, $hostParams + $params];
                }

                $allowed[$routeMethod] = true;
            }
        }

        if ($method === 'HEAD') {
            foreach ($groups as [$group, $hostParams]) {
                if (isset($this->staticRoutes[$group]['GET'][$path])) {
                    return [$this->staticRoutes[$group]['GET'][$path], $hostParams];
                }
            }
            if ($getFallback !== null) {
                return $getFallback;
            }
        }

        foreach ($groups as [$group]) {
            foreach ($this->staticRoutes[$group] ?? [] as $routeMethod => $paths) {
                if (isset($paths[$path])) {
                    $allowed[$routeMethod] = true;
                }
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
     * The matched Route and its parameters are exposed as request attributes 'route' and 'route_params'
     * (the latter includes host placeholders); 'domain' and 'domain_params' are set by match().
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
