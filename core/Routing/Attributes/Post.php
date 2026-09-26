<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Post.php
 * @brief HTTP POST route attribute.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Post extends Route
{
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct($path, 'POST', $name);
    }
}