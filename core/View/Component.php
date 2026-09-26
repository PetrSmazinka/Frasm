<?php

declare(strict_types=1);

namespace Core\View;

use ReflectionClass;
use ReflectionProperty;

/**
 * @file Component.php
 * @brief Base reactive component with state synchronization and DOM binding.
 */

/**
 * @class Component
 * @brief Manages component lifecycle, public property serialization, and template rendering.
 */
abstract class Component
{
    /**
     * @brief Path to the template file for this component.
     */
    abstract protected function template(): string;

    /**
     * @brief Serializes all public properties into an array (component state).
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
     * @brief Hydrates component public properties from incoming state payload.
     *
     * @param array<string, mixed> $state
     * @return void
     */
    public function hydrate(array $state): void
    {
        foreach ($state as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
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

        // Extract public state as template variables
        extract($this->getState(), EXTR_SKIP);

        ob_start();
        include $this->template();
        $innerHtml = (string)ob_get_clean();

        // Wrap the output with data attributes for JavaScript DOM replacement
        return "<div data-frasm-component=\"{$componentClass}\" data-frasm-state=\"{$stateJson}\">{$innerHtml}</div>";
    }
}