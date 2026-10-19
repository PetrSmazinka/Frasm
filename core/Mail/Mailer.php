<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\Config\Config;
use Core\Container\Container;
use Core\Exceptions\MailException;

/**
 * @file Mailer.php
 * @brief High-level e-mail API used by applications.
 */

/**
 * @class Mailer
 * @brief Sends messages from the sender of an account through the configured transport (mail.transport).
 *
 * @code
 * public function __construct(private Mailer $mailer) {}
 *
 * $this->mailer->send(new MailMessage('jan@example.com', 'Daily report', $text));
 * $this->mailer->account('robot')->send(new MailMessage('jan@example.com', 'Reminder', $text));
 * @endcode
 *
 * The default account is mail.from + mail.smtp. Further accounts (mail.accounts.<name>) have a sender and an SMTP
 * login of their own; what they leave out comes from the default account (a second mailbox on the same server needs
 * only its address and password). The transport is common: 'smtp', 'log' (written to the log, the default until SMTP
 * is configured) and 'array' (kept in memory, used by `php bin/frasm test` – for every account).
 */
class Mailer
{
    /**
     * @var string Name of the default account.
     */
    public const DEFAULT = 'default';

    /**
     * @brief Mailer constructor.
     *
     * @param Container $container Container (resolves the transport when sending).
     * @param string $account Account the messages are sent from (DEFAULT or a name of mail.accounts).
     */
    public function __construct(private readonly Container $container, private readonly string $account = self::DEFAULT)
    {
    }

    /**
     * @brief Returns a mailer sending from another account.
     *
     * @param string $name Name of mail.accounts (or DEFAULT).
     * @return self
     * @throws MailException On an unknown account.
     */
    public function account(string $name): self
    {
        if ($name !== self::DEFAULT && !is_array(Config::get("mail.accounts.{$name}"))) {
            throw new MailException("Unknown mail account '{$name}' (define it in mail.accounts).");
        }

        return new self($this->container, $name);
    }

    /**
     * @brief Returns the names of the accounts.
     *
     * @return list<string> DEFAULT first.
     */
    public static function accounts(): array
    {
        return [self::DEFAULT, ...array_map('strval', array_keys((array)Config::get('mail.accounts', [])))];
    }

    /**
     * @brief Sends a message now.
     *
     * @param MailMessage $message Message.
     * @return void
     * @throws MailException On an invalid configuration or when the message cannot be delivered.
     */
    public function send(MailMessage $message): void
    {
        $settings = $this->settings();
        $from = (string)($settings['from']['address'] ?? '');
        if ($from === '') {
            $from = (string)($settings['smtp']['username'] ?? '');
        }
        if ($from === '') {
            throw new MailException($this->account === self::DEFAULT ? 'Set the sender address mail.from.address.' : "Set the sender address mail.accounts.{$this->account}.from.address.");
        }

        $this->transport()->send($message, $from, (string)($settings['from']['name'] ?? ''));
    }

    /**
     * @brief Returns the transport of the account.
     *
     * @return TransportInterface
     * @throws MailException On an unknown transport.
     */
    public function transport(): TransportInterface
    {
        $transport = (string)Config::get('mail.transport', 'log');
        $smtp = $this->settings()['smtp'];

        return match ($transport) {
            'smtp'  => new SmtpTransport(
                (string)($smtp['host'] ?? ''),
                (int)($smtp['port'] ?? 465),
                (string)($smtp['encryption'] ?? 'ssl'),
                (string)($smtp['username'] ?? ''),
                (string)($smtp['password'] ?? ''),
                (int)($smtp['timeout'] ?? 15),
                (string)($smtp['ehlo'] ?? '')
            ),
            'log'   => $this->container->get(LogTransport::class),
            'array' => $this->container->get(ArrayTransport::class),
            default => throw new MailException("Unknown mail.transport '{$transport}' (smtp, log or array)."),
        };
    }

    /**
     * @brief Sender and SMTP login of the account (an account takes over what it leaves out from the default one).
     *
     * @return array{from: array<string, mixed>, smtp: array<string, mixed>}
     */
    private function settings(): array
    {
        $from = (array)Config::get('mail.from', []);
        $smtp = (array)Config::get('mail.smtp', []);
        if ($this->account !== self::DEFAULT) {
            $account = (array)Config::get("mail.accounts.{$this->account}", []);
            $from = (array)($account['from'] ?? []) + ['address' => '', 'name' => (string)($from['name'] ?? '')];
            $smtp = (array)($account['smtp'] ?? []) + $smtp;
        }

        return ['from' => $from, 'smtp' => $smtp];
    }
}
