<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Get.php
 * @brief HTTP GET route attribute.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Get extends Route
{
    public function __construct(string $path, ?string $name = null)
    {
        parent::__construct($path, 'GET', $name);
    }
}