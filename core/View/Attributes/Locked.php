<?php

declare(strict_types=1);

namespace Core\View\Attributes;

use Attribute;

/**
 * @file Locked.php
 * @brief Marks a public live component property as read-only for the client.
 */

/**
 * @class Locked
 * @brief The property is part of the signed state but cannot be changed through client updates
 *        (e.g. record ids, prices, permission flags). Only server code may modify it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Locked
{
}
