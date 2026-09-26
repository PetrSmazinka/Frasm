<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Post.php
 * @brief HTTP POST route attribute.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Post extends Route
{
    /**
     * @brief Declares an HTTP POST endpoint.
     *
     * @param string $path URL pattern (e.g. '/users/{id}').
     * @param string|null $name Optional route alias name.
     */
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct($path, 'POST', $name);
    }
}