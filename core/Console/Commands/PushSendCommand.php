<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Push\PushManager;
use Core\Push\PushMessage;
use Core\Push\PushTarget;

/**
 * @file PushSendCommand.php
 * @brief Sends a Web Push notification.
 */
final class PushSendCommand extends Command
{
    /**
     * @brief PushSendCommand constructor.
     *
     * @param PushManager $push Push manager.
     */
    public function __construct(private readonly PushManager $push)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'push:send';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Send a push notification to a user or to all subscribers';
    }

    /**
     * @brief Declares arguments.
     *
     * @return array<string, string>
     */
    public function arguments(): array
    {
        return ['title' => 'Notification title'];
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'user='    => 'Recipient user id',
            'all'      => 'Send to every subscriber (required when --user is omitted)',
            'channel=' => 'Only subscriptions that joined this channel',
            'body='    => 'Notification text',
            'url='     => 'Page opened on click',
            'queue'    => 'Enqueue instead of sending now (processed by queue:work)',
        ];
    }

    /**
     * @brief Sends or enqueues the notification.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        $user = $input->option('user');
        if ($user === null && !$input->flag('all')) {
            $output->error('Choose the recipients: --user=<id> or --all.');
            return 1;
        }
        if ($user !== null && !ctype_digit($user)) {
            $output->error('--user must be a numeric user id.');
            return 1;
        }

        $target = $user !== null ? PushTarget::user((int)$user) : PushTarget::all();
        $channel = $input->option('channel');
        if ($channel !== null) {
            $target = $target->inChannel($channel);
        }

        $message = new PushMessage((string)$input->argument('title'), $input->option('body', ''), $input->option('url'));

        if ($input->flag('queue')) {
            $output->success('Queued as job #' . $this->push->queue($message, $target));
            return 0;
        }

        $summary = $this->push->send($message, $target);
        $output->success("Sent: {$summary['sent']}, failed: {$summary['failed']}, expired (removed): {$summary['expired']}");

        return $summary['failed'] > 0 ? 1 : 0;
    }
}
