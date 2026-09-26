<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Route.php
 * @brief Base HTTP route attribute for class methods and controllers.
 */

/**
 * @class Route
 * @brief Attribute defining an HTTP route endpoint.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @brief Constructor for the route attribute.
     *
     * @param string $path URL pattern (e.g. '/users/{id}').
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.).
     * @param string|null $name Optional route alias name.
     */
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public ?string $name = null
    ) {
    }
}