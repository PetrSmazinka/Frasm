<?php

declare(strict_types=1);

namespace Core\View;

use ReflectionClass;
use ReflectionProperty;

/**
 * @file Component.php
 * @brief Base reactive component with state synchronization, DOM binding, and lifecycle hooks.
 */

/**
 * @class Component
 * @brief Manages component lifecycle, state serialization, and template rendering.
 */
abstract class Component
{
    /**
     * @brief Path to the template file for this component.
     */
    abstract protected function template(): string;

    /**
     * @brief Initialization lifecycle hook invoked once upon initial creation.
     *
     * @param mixed ...$params Arbitrary parameters passed from controller or view.
     * @return void
     */
    //public function mount(...$params): void
    //{
    //}

    /**
     * @brief General hook triggered whenever any public property is mutated by the client.
     *
     * @param string $property Property name that was updated.
     * @param mixed $value New value assigned to the property.
     * @return void
     */
    public function updated(string $property, mixed $value): void
    {
    }

    /**
     * @brief Serializes all public properties into an associative state array.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $ref = new ReflectionClass($this);
        $state = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $state[$property->getName()] = $property->getValue($this);
        }

        return $state;
    }

    /**
     * @brief Hydrates component public properties from incoming state payload with automatic type casting.
     *
     * @param array<string, mixed> $state Incoming key-value updates.
     * @param bool $triggerHooks When true, fires updated() and updated{Property}() hooks.
     * @return void
     */
    public function hydrate(array $state, bool $triggerHooks = false): void
    {
        $ref = new ReflectionClass($this);

        foreach ($state as $key => $value) {
            if (!$ref->hasProperty($key)) {
                continue;
            }

            $property = $ref->getProperty($key);
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            // Automatické přetypování podle deklarovaného typu proměnné
            $castedValue = $this->castValue($property, $value);
            $this->{$key} = $castedValue;

            if ($triggerHooks) {
                // 1. Dedikovaný hook: updatedPropertyName($value)
                $methodName = 'updated' . ucfirst($key);
                if (method_exists($this, $methodName)) {
                    $this->{$methodName}($castedValue);
                }

                // 2. Globální hook: updated($property, $value)
                $this->updated($key, $castedValue);
            }
        }
    }

    /**
     * @brief Casts raw incoming input value to match the declared property type.
     *
     * @param ReflectionProperty $property Target reflection property.
     * @param mixed $value Raw input value.
     * @return mixed Casted value matching property type.
     */
    protected function castValue(ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();
        if ($type === null || $value === null) {
            return $value;
        }

        // Pokud jde o pojmenovaný skalární typ (int, float, bool, string, array)
        if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
            return match ($type->getName()) {
                'int' => (int)$value,
                'float' => (float)$value,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'string' => (string)$value,
                'array' => (array)$value,
                default => $value,
            };
        }

        return $value;
    }

    /**
     * @brief Renders the component HTML wrapped with synchronization metadata.
     *
     * @return string
     */
    public function render(): string
    {
        $stateJson = htmlspecialchars(json_encode($this->getState(), JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
        $componentClass = htmlspecialchars(static::class, ENT_QUOTES, 'UTF-8');

        extract($this->getState(), EXTR_SKIP);

        ob_start();
        include $this->template();
        $innerHtml = (string)ob_get_clean();

        return "<div data-frasm-component=\"{$componentClass}\" data-frasm-state=\"{$stateJson}\">{$innerHtml}</div>";
    }
}