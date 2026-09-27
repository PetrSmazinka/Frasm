<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Exceptions\PushException;
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
            'user='     => 'Recipient user id',
            'all'       => 'Send to every subscriber (required when --user is omitted)',
            'channel='  => 'Only subscriptions that joined this channel',
            'body='     => 'Notification text',
            'url='      => 'Page opened on click',
            'icon='     => 'Icon next to the text (default: push.icon)',
            'image='    => 'Large picture in the expanded notification',
            'badge='    => 'Small monochrome status bar symbol (default: push.badge)',
            'tag='      => 'Replace an earlier notification with the same tag',
            'action=*'  => 'Action button "id|Title|/page" or "id|Title|post:/path" (repeatable)',
            'ttl='      => 'Seconds the push service keeps the message for offline devices',
            'urgency='  => 'very-low, low, normal or high',
            'queue'     => 'Enqueue instead of sending now (processed by queue:work)',
        ];
    }

    /**
     * @brief Returns usage examples.
     *
     * @return string
     */
    public function help(): string
    {
        return "Examples:\n"
            . "  php bin/frasm push:send \"Hello\" --user=1 --body=\"Test message\" --url=/\n"
            . "  php bin/frasm push:send \"Gate open\" --user=1 --image=/img/gate.jpg \\\n"
            . "      --action=\"camera|Camera|/camera\" --action=\"close|Close|post:/api/gate/close\"\n"
            . "  php bin/frasm push:send \"Report\" --all --channel=reports --queue\n"
            . "\n"
            . "Buttons: url actions open the page, post: actions send a background POST authorized by a\n"
            . "signed token (read it in the controller as \$request->getAttribute('push_action')).\n"
            . "Buttons and images are shown by Chromium-based browsers; others show the plain notification.";
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

        try {
            $message = new PushMessage(
                (string)$input->argument('title'),
                $input->option('body', ''),
                url: $input->option('url'),
                icon: $input->option('icon'),
                tag: $input->option('tag'),
                ttl: $input->option('ttl') === null ? null : $input->intOption('ttl', 0),
                urgency: $input->option('urgency'),
                options: array_filter([
                    'image' => $input->option('image'),
                    'badge' => $input->option('badge'),
                ], fn(?string $value): bool => $value !== null),
                actions: array_map([$this, 'parseAction'], $input->optionList('action')),
            );
            $message->toPayload();
        } catch (PushException $e) {
            $output->error($e->getMessage());
            return 1;
        }

        if ($input->flag('queue')) {
            $output->success('Queued as job #' . $this->push->queue($message, $target));
            return 0;
        }

        $summary = $this->push->send($message, $target);
        $output->success("Sent: {$summary['sent']}, failed: {$summary['failed']}, expired (removed): {$summary['expired']}");
        if ($summary['sent'] + $summary['failed'] + $summary['expired'] === 0) {
            $output->warning('No matching subscriptions: the recipient has not enabled notifications in a browser yet.');
        }

        return $summary['failed'] > 0 ? 1 : 0;
    }

    /**
     * @brief Converts "id|Title|/page" or "id|Title|post:/path" into an action definition.
     *
     * @param string $definition Action definition from --action.
     * @return array{action: string, title: string, url?: string, post?: string}
     * @throws PushException On a malformed definition (paths are validated by PushMessage).
     */
    private function parseAction(string $definition): array
    {
        $parts = explode('|', $definition, 3);
        if (count($parts) !== 3 || trim($parts[0]) === '' || trim($parts[1]) === '' || trim($parts[2]) === '') {
            throw new PushException("Invalid --action '{$definition}': expected \"id|Title|/page\" or \"id|Title|post:/path\".");
        }

        [$id, $title, $target] = array_map('trim', $parts);

        return str_starts_with($target, 'post:')
            ? ['action' => $id, 'title' => $title, 'post' => substr($target, 5)]
            : ['action' => $id, 'title' => $title, 'url' => $target];
    }
}
