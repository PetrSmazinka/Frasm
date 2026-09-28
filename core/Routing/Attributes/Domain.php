<?php

declare(strict_types=1);

namespace Core\Routing\Attributes;

use Attribute;

/**
 * @file Domain.php
 * @brief Binds the routes of a controller or of one action to a named domain.
 */

/**
 * @class Domain
 * @brief Attribute restricting routes to the hosts of one `app.domains` entry.
 *
 * On a class it applies to every action; on a method it overrides the class. Placeholders of the
 * domain ('{tenant}.example.com') are passed to the action like route parameters.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Domain
{
    /**
     * @brief Constructor for the domain attribute.
     *
     * @param string $name Domain name defined in `app.domains`.
     */
    public function __construct(
        public string $name
    ) {
    }
}
