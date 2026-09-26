<?php

declare(strict_types=1);

namespace Core\Exceptions;

/**
 * @file ModuleException.php
 * @brief Base exception for framework modules and extensions.
 */

/**
 * @class ModuleException
 * @brief Raised when errors occur inside decoupled domain modules or add-ons.
 */
class ModuleException extends FrasmException
{
}