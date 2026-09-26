<?php

declare(strict_types=1);

/**
 * @file push.php
 * @brief Web Push console tool: VAPID key generation and test notifications.
 *
 * Usage:
 *   php bin/push.php vapid
 *   php bin/push.php send <user_id> <title> [body] [url]
 *   php bin/push.php broadcast <title> [body] [url]
 */

use Core\Container\Container;
use Core\Push\PushManager;
use Core\Push\PushMessage;
use Core\Push\Vapid;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$action = $argv[1] ?? 'help';

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
            $userId = $isSend ? ($argv[2] ?? null) : null;
            $title = $argv[$isSend ? 3 : 2] ?? null;

            if (($isSend && ($userId === null || !ctype_digit($userId))) || $title === null) {
                echo "Usage: php bin/push.php send <user_id> <title> [body] [url]\n";
                echo "       php bin/push.php broadcast <title> [body] [url]\n";
                exit(1);
            }

            $message = new PushMessage(
                $title,
                $argv[$isSend ? 4 : 3] ?? '',
                $argv[$isSend ? 5 : 4] ?? null
            );

            /** @var PushManager $push */
            $push = Container::getInstance()->get(PushManager::class);
            $summary = $isSend ? $push->sendToUser((int)$userId, $message) : $push->broadcast($message);

            echo "✔ Sent: {$summary['sent']}, failed: {$summary['failed']}, expired (removed): {$summary['expired']}\n";
            break;

        default:
            echo "Frasm Web Push Tool\n";
            echo "-------------------\n";
            echo "Commands:\n";
            echo "  vapid                                   Generate a VAPID key pair\n";
            echo "  send <user_id> <title> [body] [url]     Notify all devices of a user\n";
            echo "  broadcast <title> [body] [url]          Notify every subscriber\n";
            exit(0);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "✖ Error: " . $e->getMessage() . "\n");
    exit(1);
}
