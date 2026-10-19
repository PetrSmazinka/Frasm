<?php

declare(strict_types=1);

namespace Core\Mail;

/**
 * @file ArrayTransport.php
 * @brief Keeps e-mails in memory instead of sending them (tests).
 */

/**
 * @class ArrayTransport
 * @brief Collects the messages; `php bin/frasm test` uses it, so tests never send real e-mails.
 *
 * @code
 * $sent = Container::getInstance()->get(ArrayTransport::class)->messages();
 * @endcode
 */
class ArrayTransport implements TransportInterface
{
    /**
     * @var list<array{message: MailMessage, from: string, from_name: string}> Delivered messages.
     */
    private array $messages = [];

    /**
     * @brief Stores a message.
     *
     * @param MailMessage $message Message.
     * @param string $from Sender address.
     * @param string $fromName Sender display name.
     * @return void
     */
    public function send(MailMessage $message, string $from, string $fromName): void
    {
        $this->messages[] = ['message' => $message, 'from' => $from, 'from_name' => $fromName];
    }

    /**
     * @brief Returns the delivered messages, oldest first.
     *
     * @return list<MailMessage>
     */
    public function messages(): array
    {
        return array_column($this->messages, 'message');
    }

    /**
     * @brief Returns the messages with their senders, oldest first.
     *
     * @return list<array{message: MailMessage, from: string, from_name: string}>
     */
    public function sent(): array
    {
        return $this->messages;
    }

    /**
     * @brief Forgets the delivered messages.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->messages = [];
    }
}
