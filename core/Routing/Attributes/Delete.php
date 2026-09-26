<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Delete.php
 * @brief HTTP DELETE route attribute.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Delete extends Route
{
    /**
     * @brief Declares an HTTP DELETE endpoint.
     *
     * @param string $path URL pattern (e.g. '/users/{id}').
     * @param string|null $name Optional route alias name.
     */
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct($path, 'DELETE', $name);
    }
}
