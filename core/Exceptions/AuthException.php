<?php

declare(strict_types=1);

namespace Core\Exceptions;

/**
 * @file AuthException.php
 * @brief Authentication and authorization exception.
 */

/**
 * @class AuthException
 * @brief Raised on failed authentication, expired sessions, or access control violations.
 */
class AuthException extends CoreException
{
}