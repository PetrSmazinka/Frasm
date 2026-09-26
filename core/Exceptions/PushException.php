<?php

declare(strict_types=1);

namespace Core\Exceptions;

/**
 * @file PushException.php
 * @brief Web Push configuration, cryptography and delivery exception.
 */

/**
 * @class PushException
 * @brief Raised on invalid VAPID configuration, malformed subscriptions or encryption failures.
 */
class PushException extends CoreException
{
}
