<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Exception;

/**
 * @file SkippedTestException.php
 * @brief A test decided not to run (TestCase::skip()), e.g. because a tool it needs is missing.
 *
 * Control flow of the test runner, outside the FrasmException hierarchy.
 */
class SkippedTestException extends Exception
{
}
