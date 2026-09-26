<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Config\Config;
use Core\Container\Container;
use Core\Error\ErrorHandler;
use Core\Http\Middleware\MiddlewareResolver;
use Core\Http\Middleware\Pipeline;
use Core\I18n\Lang;
use Core\Logger\Log;
use Core\Push\PushController;
use Core\Routing\RouteCache;
use Core\Routing\Router;
use Core\View\Live\LiveComponentHandler;
use ReflectionClass;
use Throwable;

/**
 * @file Kernel.php
 * @brief HTTP kernel turning a Request into a Response.
 */

/**
 * @class Kernel
 * @brief Registers routes, runs the global middleware pipeline, dispatches to the router and
 *        converts every failure into an error response.
 *
 * Request lifecycle:
 *   global middleware (`middleware.global`) → Router::match → route middleware
 *   (`middleware.paths`, #[Authorize], #[Middleware]) → controller action → Response.
 * Exceptions are rendered at the pipeline stage where they occur, so outer middleware can still
 * decorate error responses.
 */
class Kernel implements RequestHandlerInterface
{
    /**
     * @var list<class-string> Framework controllers registered before application controllers.
     */
    public const CORE_HANDLERS = [
        LiveComponentHandler::class,
        PushController::class,
    ];

    /**
     * @var bool Whether routes have been registered.
     */
    protected bool $booted = false;

    /**
     * @brief Kernel constructor.
     *
     * @param Container $container Service container.
     * @param Router $router Application router.
     * @param ErrorHandler $errorHandler Error reporter/renderer.
     * @param MiddlewareResolver $middlewareResolver Middleware specification resolver.
     * @param RouteCache $routeCache Compiled route table storage.
     */
    public function __construct(
        protected Container $container,
        protected Router $router,
        protected ErrorHandler $errorHandler,
        protected MiddlewareResolver $middlewareResolver,
        protected RouteCache $routeCache
    ) {
    }

    /**
     * @brief Registers framework and application routes (once), using the route cache when enabled.
     *
     * With a valid cache no controller file is scanned, loaded or reflected; only the matched
     * controller is autoloaded during dispatch.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if (!$this->routeCache->isEnabled()) {
            $this->registerRoutes($this->discoverControllers());
            return;
        }

        $cached = $this->routeCache->load();
        $controllers = null;

        if ($cached !== null) {
            if (!$this->routeCache->shouldValidate()) {
                $this->router->importRoutes($cached['routes']);
                return;
            }

            $controllers = $this->discoverControllers();
            if ($this->routeCache->isFresh($cached, array_values($controllers))) {
                $this->router->importRoutes($cached['routes']);
                return;
            }
        }

        $this->rebuildRouteCache($controllers ?? $this->discoverControllers());
    }

    /**
     * @brief Registers routes from reflection and writes them to the route cache.
     *
     * @param array<string, string>|null $controllers Application controllers (class => file); null discovers them.
     * @return int Number of cached routes.
     */
    public function rebuildRouteCache(?array $controllers = null): int
    {
        $controllers ??= $this->discoverControllers();
        $this->registerRoutes($controllers);

        $sources = array_values($controllers);
        foreach (self::CORE_HANDLERS as $handler) {
            $file = (new ReflectionClass($handler))->getFileName();
            if ($file !== false) {
                $sources[] = $file;
            }
        }

        $routes = $this->router->exportRoutes();
        if (!$this->routeCache->write($routes, $sources)) {
            Log::notice('Route cache could not be written to {path}; routes are rebuilt on every request.', [
                'path' => $this->routeCache->path(),
            ]);
        }

        return count($routes);
    }

    /**
     * @brief Returns the router used by the kernel.
     *
     * @return Router
     */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * @brief Registers framework handlers and the given application controllers by reflection.
     *
     * @param array<string, string> $controllers Application controllers (class => file).
     * @return void
     */
    protected function registerRoutes(array $controllers): void
    {
        foreach (self::CORE_HANDLERS as $handler) {
            $this->router->registerController($handler);
        }

        foreach ($controllers as $class => $file) {
            if (!class_exists($class, false)) {
                require_once $file;
            }
            if (class_exists($class, false)) {
                $this->router->registerController($class);
            }
        }
    }

    /**
     * @brief Discovers application controllers in the configured directory.
     *
     * @return array<string, string> Class => file.
     */
    protected function discoverControllers(): array
    {
        return $this->router->discoverControllers(
            (string)Config::get('routing.controllers_path', FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Controllers'),
            (string)Config::get('routing.controllers_namespace', 'App\\Controllers\\')
        );
    }

    /**
     * @brief Handles the request and always returns a response (never throws).
     *
     * @param Request $request Incoming request.
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $this->container->instance(Request::class, $request);
        $this->errorHandler->setRequest($request);

        $renderer = fn(Throwable $e, Request $r): Response => $this->errorHandler->handle($e, $r);

        try {
            Lang::boot();
            $this->boot();

            /** @var list<string> $globalSpecs */
            $globalSpecs = (array)Config::get('middleware.global', []);
            $core = new CallableHandler(fn(Request $r): Response => $this->router->dispatch($r, $renderer));

            return (new Pipeline($this->middlewareResolver->resolve($globalSpecs), $core, $renderer))->handle($request);
        } catch (Throwable $e) {
            return $renderer($e, $request);
        }
    }

    /**
     * @brief Sends the response (without body for HEAD requests).
     *
     * @param Request $request Handled request.
     * @param Response $response Response to send.
     * @return void
     */
    public function send(Request $request, Response $response): void
    {
        $response->send($request->realMethod() !== 'HEAD');
    }
}
