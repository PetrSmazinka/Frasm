<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Exceptions\AuthException;
use Core\Exceptions\CoreException;
use Core\Exceptions\MethodNotAllowedException;
use Core\Exceptions\RouteNotFoundException;
use Core\Routing\Attributes\Authorize;
use Core\Routing\Attributes\Route as RouteAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * @file Router.php
 * @brief Central HTTP request router with PHP 8 attribute scanning support.
 */

/**
 * @class Router
 * @brief Manages route registration, controller reflection, and URL dispatching.
 */
class Router
{
    /**
     * @var list<Route> Registered route instances.
     */
    protected array $routes = [];

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
        $this->routes[] = $route;
        return $route;
    }

    public function get(string $path, mixed $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * @brief Scans a controller class using PHP Reflection and registers attributed routes.
     *
     * Reads #[Route], #[Get], #[Post], and #[Authorize] attributes.
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

        // Class-level path prefix
        $basePath = '';
        foreach ($refClass->getAttributes() as $classAttr) {
            $instance = $classAttr->newInstance();
            if ($instance instanceof RouteAttribute) {
                $basePath = trim($instance->path, '/');
                break;
            }
        }

        // Class-level authorization
        $classRoles = null;
        foreach ($refClass->getAttributes() as $classAttr) {
            $instance = $classAttr->newInstance();
            if ($instance instanceof Authorize) {
                $classRoles = $instance->roles;
                break;
            }
        }

        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodRoles = $classRoles;

            // Check method-level authorization override
            foreach ($method->getAttributes() as $attr) {
                $instance = $attr->newInstance();
                if ($instance instanceof Authorize) {
                    $methodRoles = $instance->roles;
                }
            }

            // Scan method route attributes
            foreach ($method->getAttributes() as $attr) {
                $instance = $attr->newInstance();

                if ($instance instanceof RouteAttribute) {
                    $methodPath = trim($instance->path, '/');

                    $segments = array_filter([$basePath, $methodPath], fn(string $s): bool => $s !== '');
                    $fullPath = '/' . implode('/', $segments);

                    $route = $this->addRoute(
                        $instance->method,
                        $fullPath,
                        [$controllerClass, $method->getName()]
                    );

                    if ($methodRoles !== null) {
                        $route->setMetadata('auth_roles', $methodRoles);
                    }
                }
            }
        }
    }

    /**
     * @brief Dispatches the incoming HTTP request against registered routes.
     *
     * @param string $requestMethod HTTP method from request (e.g. $_SERVER['REQUEST_METHOD']).
     * @param string $requestUri Request URI from server (e.g. $_SERVER['REQUEST_URI']).
     * @return mixed Handler output.
     * @throws RouteNotFoundException If no matching route pattern is found.
     * @throws MethodNotAllowedException If the route exists but rejects the HTTP method.
     * @throws AuthException If route authorization requirements are not met.
     */
    public function dispatch(string $requestMethod, string $requestUri): mixed
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

        // Strip subdirectory prefix if project is not running from webroot
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        $normalizedPath = '/' . trim($path, '/');
        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        $method = strtoupper($requestMethod);
        $matchedPathWithDifferentMethod = false;

        foreach ($this->routes as $route) {
            $params = $route->match($normalizedPath);
            if ($params === null) {
                continue;
            }

            if ($route->getMethod() !== $method) {
                $matchedPathWithDifferentMethod = true;
                continue;
            }

            // Route matched: verify authorization requirements
            $this->enforceAuthorization($route);

            return $this->executeHandler($route->getHandler(), $params);
        }

        if ($matchedPathWithDifferentMethod) {
            throw new MethodNotAllowedException("Method {$method} not allowed for path: {$normalizedPath}", 405);
        }

        throw new RouteNotFoundException("No route found for path: {$normalizedPath}", 404);
    }

    /**
     * @brief Verifies authentication/authorization metadata attached to the route.
     *
     * @param Route $route Matched route instance.
     * @return void
     * @throws CoreException If #[Authorize] is used while the Auth module is disabled.
     * @throws AuthException If current user lacks permissions.
     */
    protected function enforceAuthorization(Route $route): void
    {
        $requiredRoles = $route->getMetadata('auth_roles');
        if ($requiredRoles === null) {
            return;
        }

        // Verify if authentication module is enabled in configuration
        if (!\Core\Config\Config::get('auth.enabled', true)) {
            throw new CoreException(
                "Route '{$route->getPath()}' specifies #[Authorize], but the Auth module is disabled in config/auth.php.",
                500
            );
        }

        // 1. User is not authenticated
        if (!\Core\Auth\Auth::check()) {
            throw new AuthException("User is unauthenticated.", 401);
        }

        // 2. #[Authorize] without specific roles: being logged in is sufficient
        if (empty($requiredRoles)) {
            return;
        }

        // 3. User must possess at least one matching role
        if (!\Core\Auth\Auth::hasAnyRole($requiredRoles)) {
            throw new AuthException("User does not have required permissions.", 403);
        }
    }

    /**
     * @brief Executes the matched handler passing extracted parameters.
     *
     * @param mixed $handler Callable or [ClassName, MethodName].
     * @param array<string, string> $params Extracted parameters.
     * @return mixed
     * @throws CoreException If handler is invalid.
     */
    protected function executeHandler(mixed $handler, array $params): mixed
    {
        if (is_callable($handler)) {
            return $handler(...$params);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;

            if (!class_exists($class)) {
                throw new CoreException("Controller class '{$class}' not found.", 500);
            }

            $instance = new $class();

            if (!method_exists($instance, $action)) {
                throw new CoreException("Action '{$action}' not found in '{$class}'.", 500);
            }

            return $instance->$action(...$params);
        }

        throw new CoreException("Invalid route handler format.", 500);
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
        $realDirectoryPath = realpath($directoryPath);
        if ($realDirectoryPath === false || !is_dir($realDirectoryPath)) {
            return;
        }

        $baseNamespace = rtrim($baseNamespace, '\\') . '\\';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDirectoryPath, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }

            // Derive namespace suffix from directory nesting
            $fileDir = $file->getPath();
            $subPath = trim(substr($fileDir, strlen($realDirectoryPath)), DIRECTORY_SEPARATOR);
            $subNamespace = '';
            if ($subPath !== '') {
                $subNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $subPath) . '\\';
            }

            $className = $file->getBasename('.php');
            $fullClassName = $baseNamespace . $subNamespace . $className;

            // Load file explicitly if not yet registered in runtime
            if (!class_exists($fullClassName, false)) {
                require_once $file->getRealPath();
            }

            if (class_exists($fullClassName)) {
                $this->registerController($fullClassName);
            }
        }
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
}