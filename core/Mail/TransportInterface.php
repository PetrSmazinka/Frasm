<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Exceptions\MailException;

/**
 * @file TransportInterface.php
 * @brief Delivery of composed e-mails.
 */

/**
 * @interface TransportInterface
 * @brief Delivers one message (SMTP server, log, memory of the tests).
 */
interface TransportInterface
{
    /**
     * @brief Delivers a message.
     *
     * @param MailMessage $message Message.
     * @param string $from Sender address (envelope and From header).
     * @param string $fromName Sender display name (may be empty).
     * @return void
     * @throws MailException When the message cannot be delivered.
     */
    public function send(MailMessage $message, string $from, string $fromName): void;
}
