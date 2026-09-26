<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Authorize.php
 * @brief Access control attribute.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Authorize
{
    /**
     * @param list<string>|string $roles Required user role(s).
     */
    public function __construct(
        public array|string $roles = []
    ) {
        if (is_string($this->roles)) {
            $this->roles = [$this->roles];
        }
    }
}