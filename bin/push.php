<?php

declare(strict_types=1);

/**
 * @file push.php
 * @brief Web Push console tool: VAPID key generation and test notifications.
 *
 * Usage:
 *   php bin/push.php vapid
 *   php bin/push.php send <user_id> <title> [body] [url] [--queue]
 *   php bin/push.php broadcast <title> [body] [url] [--channel=name] [--queue]
 */

use Core\Container\Container;
use Core\Push\PushManager;
use Core\Push\PushMessage;
use Core\Push\PushTarget;
use Core\Push\Vapid;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$action = $argv[1] ?? 'help';

// Split positional arguments from --options
$positional = [];
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches)) {
        $options[$matches[1]] = $matches[2] ?? true;
    } else {
        $positional[] = $argument;
    }
}

try {
    switch ($action) {
        case 'vapid':
            $keys = Vapid::generateKeys();
            echo "Add to config/local.php (keep the private key secret):\n\n";
            echo "    'push' => [\n";
            echo "        'enabled' => true,\n";
            echo "        'vapid' => [\n";
            echo "            'subject'     => 'mailto:you@example.com',\n";
            echo "            'public_key'  => '{$keys['public_key']}',\n";
            echo "            'private_key' => '{$keys['private_key']}',\n";
            echo "        ],\n";
            echo "    ],\n\n";
            echo "Changing the keys later invalidates all existing browser subscriptions.\n";
            break;

        case 'send':
        case 'broadcast':
            $isSend = $action === 'send';
            $userId = $isSend ? ($positional[1] ?? null) : null;
            $offset = $isSend ? 2 : 1;
            $title = $positional[$offset] ?? null;

            if (($isSend && ($userId === null || !ctype_digit($userId))) || $title === null) {
                echo "Usage: php bin/push.php send <user_id> <title> [body] [url] [--queue]\n";
                echo "       php bin/push.php broadcast <title> [body] [url] [--channel=name] [--queue]\n";
                exit(1);
            }

            $message = new PushMessage($title, $positional[$offset + 1] ?? '', $positional[$offset + 2] ?? null);
            $target = $isSend ? PushTarget::user((int)$userId) : PushTarget::all();
            if (isset($options['channel']) && is_string($options['channel'])) {
                $target = $target->inChannel($options['channel']);
            }

            /** @var PushManager $push */
            $push = Container::getInstance()->get(PushManager::class);

            if (isset($options['queue'])) {
                echo "✔ Queued as job #" . $push->queue($message, $target) . " (run `php bin/queue.php work --once`).\n";
                break;
            }

            $summary = $push->send($message, $target);
            echo "✔ Sent: {$summary['sent']}, failed: {$summary['failed']}, expired (removed): {$summary['expired']}\n";
            break;

        default:
            echo "Frasm Web Push Tool\n";
            echo "-------------------\n";
            echo "Commands:\n";
            echo "  vapid                                   Generate a VAPID key pair\n";
            echo "  send <user_id> <title> [body] [url] [--queue]                   Notify all devices of a user\n";
            echo "  broadcast <title> [body] [url] [--channel=name] [--queue]       Notify every (channel) subscriber\n";
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error: " . $e->getMessage() . "\n");
    exit(1);
}
