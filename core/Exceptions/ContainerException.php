<?php

declare(strict_types=1);

namespace Core\Exceptions;

/**
 * @file ContainerException.php
 * @brief Dependency injection container resolution exception.
 */

/**
 * @class ContainerException
 * @brief Raised when a service cannot be resolved, instantiated or a circular dependency is detected.
 */
class ContainerException extends CoreException
{
}
