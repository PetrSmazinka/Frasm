<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Get.php
 * @brief HTTP GET route attribute.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Get extends Route
{
    /**
     * @brief Declares an HTTP GET endpoint.
     *
     * @param string $path URL pattern (e.g. '/users/{id}').
     * @param string|null $name Optional route alias name.
     */
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct($path, 'GET', $name);
    }
}