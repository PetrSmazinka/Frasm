<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Logger\LoggerInterface;

/**
 * @file LogTransport.php
 * @brief Writes e-mails to the log instead of sending them (development, SMTP not configured yet).
 */

/**
 * @class LogTransport
 * @brief Logs recipients, subject and the plain text body at the info level.
 */
class LogTransport implements TransportInterface
{
    /**
     * @brief LogTransport constructor.
     *
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @brief Logs a message.
     *
     * @param MailMessage $message Message.
     * @param string $from Sender address.
     * @param string $fromName Sender display name.
     * @return void
     */
    public function send(MailMessage $message, string $from, string $fromName): void
    {
        $this->logger->info('E-mail not sent (mail.transport = log)', [
            'from' => $from, 'to' => implode(', ', $message->recipients()), 'subject' => $message->subject, 'text' => $message->text,
        ]);
    }
}
