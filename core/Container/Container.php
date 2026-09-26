<?php

declare(strict_types=1);

namespace Core\Container;

use Closure;
use Core\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * @file Container.php
 * @brief Lightweight dependency injection container with reflection-based autowiring.
 */

/**
 * @class Container
 * @brief Resolves services by identifier, autowires constructor dependencies and injects method arguments.
 *
 * Resolution rules for every constructor/method parameter (first match wins):
 *  1. An explicitly supplied parameter matching the parameter name.
 *  2. A service resolved from the class/interface type hint (bindings first, then autowiring).
 *  3. The declared default value.
 *  4. null for nullable parameters.
 * Otherwise a ContainerException is thrown.
 */
class Container
{
    /**
     * @var static|null Globally shared container instance.
     */
    private static ?Container $instance = null;

    /**
     * @var array<string, array{factory: Closure(Container, array<string, mixed>): mixed, shared: bool}> Registered bindings.
     */
    protected array $bindings = [];

    /**
     * @var array<string, mixed> Resolved shared instances.
     */
    protected array $instances = [];

    /**
     * @var array<string, true> Identifiers currently being resolved (circular dependency guard).
     */
    protected array $resolving = [];

    /**
     * @var array<class-string, list<ReflectionParameter>|null> Cached constructor parameters (null = no constructor).
     */
    protected array $constructorCache = [];

    /**
     * @brief Returns the globally shared container, creating it on first access.
     *
     * @return Container
     */
    public static function getInstance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new static();
            self::$instance->instance(self::class, self::$instance);
        }

        return self::$instance;
    }

    /**
     * @brief Replaces (or clears with null) the globally shared container.
     *
     * @param Container|null $container New global container.
     * @return void
     */
    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    /**
     * @brief Registers a transient binding: a new value is produced on every resolution.
     *
     * @param string $id Service identifier (usually an interface or class name).
     * @param Closure|string|null $concrete Factory closure `fn(Container $c, array $params)`, concrete class name, or null to autowire $id itself.
     * @return void
     */
    public function bind(string $id, Closure|string|null $concrete = null): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = ['factory' => $this->normalizeConcrete($id, $concrete), 'shared' => false];
    }

    /**
     * @brief Registers a shared binding: the value is produced once and then reused.
     *
     * @param string $id Service identifier.
     * @param Closure|string|null $concrete Factory closure, concrete class name, or null to autowire $id itself.
     * @return void
     */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = ['factory' => $this->normalizeConcrete($id, $concrete), 'shared' => true];
    }

    /**
     * @brief Registers an already constructed value as a shared service.
     *
     * @param string $id Service identifier.
     * @param mixed $value Service instance or value.
     * @return void
     */
    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    /**
     * @brief Checks whether an identifier is bound, instantiated, or autowirable.
     *
     * @param string $id Service identifier.
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || array_key_exists($id, $this->instances)
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }

    /**
     * @brief Checks whether an identifier has an explicit binding or registered instance.
     *
     * @param string $id Service identifier.
     * @return bool
     */
    public function bound(string $id): bool
    {
        return isset($this->bindings[$id]) || array_key_exists($id, $this->instances);
    }

    /**
     * @brief Resolves a service, honoring shared bindings.
     *
     * @template T of object
     * @param class-string<T>|string $id Service identifier.
     * @return ($id is class-string<T> ? T : mixed)
     * @throws ContainerException If the service cannot be resolved.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        return $this->resolve($id, []);
    }

    /**
     * @brief Resolves a service with explicit constructor parameters.
     *
     * Shared bindings are bypassed when parameters are supplied, because the result depends on them.
     *
     * @template T of object
     * @param class-string<T>|string $id Service identifier.
     * @param array<string, mixed> $parameters Constructor arguments keyed by parameter name.
     * @return ($id is class-string<T> ? T : mixed)
     * @throws ContainerException If the service cannot be resolved.
     */
    public function make(string $id, array $parameters = []): mixed
    {
        if ($parameters === [] && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        return $this->resolve($id, $parameters);
    }

    /**
     * @brief Invokes a callable, injecting its arguments.
     *
     * Supported callables: Closure, invokable object, [object, 'method'], [class-string, 'method']
     * (the class is resolved through the container for non-static methods) and 'Class::method'.
     *
     * @param callable|array{0: object|class-string, 1: string}|string $callable Target to invoke.
     * @param array<string, mixed> $parameters Arguments keyed by parameter name.
     * @return mixed Return value of the callable.
     * @throws ContainerException If the callable is invalid or an argument cannot be resolved.
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        try {
            if (is_array($callable)) {
                [$target, $method] = $callable;
                $reflection = new ReflectionMethod($target, (string)$method);

                if (!$reflection->isPublic()) {
                    throw new ContainerException("Method '{$reflection->class}::{$reflection->name}' is not public.");
                }

                $object = null;
                if (!$reflection->isStatic()) {
                    $object = is_object($target) ? $target : $this->get((string)$target);
                }

                return $reflection->invokeArgs($object, $this->resolveParameters($reflection, $parameters));
            }

            if (is_object($callable) && !$callable instanceof Closure) {
                $reflection = new ReflectionMethod($callable, '__invoke');
                return $reflection->invokeArgs($callable, $this->resolveParameters($reflection, $parameters));
            }

            $reflection = new ReflectionFunction(Closure::fromCallable($callable));
            return $reflection->invokeArgs($this->resolveParameters($reflection, $parameters));
        } catch (ReflectionException $e) {
            throw new ContainerException("Cannot invoke callable: " . $e->getMessage(), 500, $e);
        }
    }

    /**
     * @brief Resolves an identifier through its binding or by autowiring.
     *
     * @param string $id Service identifier.
     * @param array<string, mixed> $parameters Explicit constructor arguments.
     * @return mixed
     * @throws ContainerException On unresolvable or circular dependencies.
     */
    protected function resolve(string $id, array $parameters): mixed
    {
        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving));
            throw new ContainerException("Circular dependency detected while resolving '{$id}' ({$chain} -> {$id}).");
        }

        $this->resolving[$id] = true;

        try {
            if (isset($this->bindings[$id])) {
                $binding = $this->bindings[$id];
                $value = ($binding['factory'])($this, $parameters);

                if ($binding['shared'] && $parameters === []) {
                    $this->instances[$id] = $value;
                }

                return $value;
            }

            return $this->build($id, $parameters);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * @brief Instantiates a concrete class, autowiring its constructor dependencies.
     *
     * @param string $class Fully qualified class name.
     * @param array<string, mixed> $parameters Explicit constructor arguments keyed by name.
     * @return object
     * @throws ContainerException If the class does not exist or is not instantiable.
     */
    protected function build(string $class, array $parameters): object
    {
        if (!array_key_exists($class, $this->constructorCache)) {
            if (interface_exists($class)) {
                throw new ContainerException("Cannot resolve interface '{$class}': no binding registered.");
            }
            if (!class_exists($class)) {
                throw new ContainerException("Cannot resolve '{$class}': no binding registered and class does not exist.");
            }

            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new ContainerException("Cannot resolve '{$class}': class is not instantiable (interface, abstract or private constructor) and has no binding.");
            }

            $constructor = $reflection->getConstructor();
            $this->constructorCache[$class] = $constructor?->getParameters();
        }

        $constructorParameters = $this->constructorCache[$class];
        if ($constructorParameters === null) {
            return new $class();
        }

        return new $class(...$this->resolveParameterList($constructorParameters, $parameters, $class));
    }

    /**
     * @brief Resolves arguments for a reflected function or method.
     *
     * @param ReflectionFunctionAbstract $function Reflected target.
     * @param array<string, mixed> $parameters Explicit arguments keyed by name.
     * @return list<mixed> Positional argument list.
     * @throws ContainerException If an argument cannot be resolved.
     */
    protected function resolveParameters(ReflectionFunctionAbstract $function, array $parameters): array
    {
        $owner = $function instanceof ReflectionMethod ? $function->class . '::' . $function->name : $function->name;
        return $this->resolveParameterList($function->getParameters(), $parameters, $owner);
    }

    /**
     * @brief Resolves a list of reflected parameters into a positional argument list.
     *
     * @param list<ReflectionParameter> $reflectionParameters Parameters to resolve.
     * @param array<string, mixed> $parameters Explicit arguments keyed by name.
     * @param string $owner Human readable owner name for error messages.
     * @return list<mixed>
     * @throws ContainerException If a required argument cannot be resolved.
     */
    protected function resolveParameterList(array $reflectionParameters, array $parameters, string $owner): array
    {
        $arguments = [];

        foreach ($reflectionParameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $parameters)) {
                $arguments[] = $parameters[$name];
                continue;
            }

            if ($parameter->isVariadic()) {
                break;
            }

            $resolved = false;
            foreach ($this->classTypesOf($parameter) as $type) {
                if ($this->bound($type) || class_exists($type)) {
                    try {
                        $arguments[] = $this->get($type);
                        $resolved = true;
                        break;
                    } catch (ContainerException $e) {
                        if (!$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()) {
                            throw $e;
                        }
                    }
                }
            }

            if ($resolved) {
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new ContainerException("Unresolvable parameter '\${$name}' of '{$owner}'.");
        }

        return $arguments;
    }

    /**
     * @brief Extracts non-builtin (class or interface) type names from a parameter type declaration.
     *
     * @param ReflectionParameter $parameter Reflected parameter.
     * @return list<string>
     */
    protected function classTypesOf(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        $classes = [];
        foreach ($types as $candidate) {
            if (!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin()) {
                continue;
            }

            $name = $candidate->getName();
            if ($name === 'self' || $name === 'static') {
                $name = $parameter->getDeclaringClass()?->getName() ?? $name;
            }
            $classes[] = $name;
        }

        return $classes;
    }

    /**
     * @brief Converts a binding definition into a uniform factory closure.
     *
     * @param string $id Service identifier.
     * @param Closure|string|null $concrete Factory closure, class name or null.
     * @return Closure(Container, array<string, mixed>): mixed
     */
    protected function normalizeConcrete(string $id, Closure|string|null $concrete): Closure
    {
        if ($concrete instanceof Closure) {
            return $concrete;
        }

        $class = $concrete ?? $id;

        if ($class === $id) {
            return fn(Container $container, array $parameters): object => $container->build($class, $parameters);
        }

        return fn(Container $container, array $parameters): mixed => $container->make($class, $parameters);
    }
}
