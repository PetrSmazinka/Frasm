<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Config\Config;
use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Exceptions\MailException;
use Core\Mail\Mailer;
use Core\Mail\MailMessage;

/**
 * @file MailTestCommand.php
 * @brief Sends a test e-mail to check the mail configuration.
 */
final class MailTestCommand extends Command
{
    /**
     * @brief MailTestCommand constructor.
     *
     * @param Mailer $mailer Mailer.
     */
    public function __construct(private readonly Mailer $mailer)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'mail:test';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Send a test e-mail through the configured transport';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['to' => 'Recipient address'];
    }

    /**
     * @brief Options of the command.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return ['account=' => 'Account of mail.accounts to send from (default: the default account)'];
    }

    /**
     * @brief Sends the message.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $transport = (string)Config::get('mail.transport', 'log');
        try {
            $this->mailer->account((string)$input->option('account', Mailer::DEFAULT))->send(new MailMessage(
                (string)$input->argument('to'),
                'Frasm: test e-mail',
                "This message checks the e-mail configuration of " . Config::get('app.name', 'the application') . ".\nSent " . date('Y-m-d H:i:s') . '.'
            ));
        } catch (MailException $e) {
            $output->error($e->getMessage());
            return 1;
        }

        if ($transport !== 'smtp') {
            $output->warning("mail.transport is '{$transport}': the message was not sent, only " . ($transport === 'log' ? 'logged.' : 'kept in memory.'));
            return 0;
        }
        $output->success('Sent to ' . $input->argument('to') . '.');

        return 0;
    }
}
