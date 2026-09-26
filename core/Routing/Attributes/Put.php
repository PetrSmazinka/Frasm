<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Put.php
 * @brief HTTP PUT route attribute.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Put extends Route
{
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct($path, 'PUT', $name);
    }
}