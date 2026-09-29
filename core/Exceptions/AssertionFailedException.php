<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Exception;

/**
 * @file AssertionFailedException.php
 * @brief An assertion of a test did not hold (Core\Testing).
 *
 * Outside the FrasmException hierarchy and not a RuntimeException, so application code catching
 * those never swallows a failing assertion.
 */
class AssertionFailedException extends Exception
{
}
