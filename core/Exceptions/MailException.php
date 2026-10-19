<?php

declare(strict_types=1);

namespace Core\Exceptions;

/**
 * @file MailException.php
 * @brief E-mail composition and delivery exception.
 */

/**
 * @class MailException
 * @brief Raised on an invalid message or configuration and when the SMTP server refuses or cannot be reached.
 */
class MailException extends CoreException
{
}
