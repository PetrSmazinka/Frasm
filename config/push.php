<?php

declare(strict_types=1);

/**
 * Web Push notifications (VAPID, RFC 8030/8291/8292)
 *
 * Setup: 1) `php bin/frasm push:vapid` and copy the printed keys into config/local.php,
 *        2) set 'enabled' => true, 3) `php bin/frasm db:migrate` (creates frasm_push_subscriptions),
 *        4) include frasm_head() in the layout and call Frasm.push.subscribe() from a button.
 */
return [
    'enabled' => false,

    'vapid' => [
        // Contact of the operator, sent to push services ('mailto:' or 'https:' URI)
        'subject'     => 'mailto:admin@example.com',
        // Keep keys out of version control: set them in config/local.php
        'public_key'  => '',
        'private_key' => '',
    ],

    // Only logged-in users may subscribe; subscriptions are bound to their user id
    'require_auth' => true,

    // Oldest subscriptions beyond this count are removed per user (0 = unlimited)
    'max_subscriptions_per_user' => 10,

    // Defaults for PushMessage (seconds the push service stores undelivered messages; urgency)
    'ttl'     => 86400,
    'urgency' => 'normal',

    // Delivery: parallel requests and per-request timeout (seconds)
    'concurrency' => 10,
    'timeout'     => 10,

    // Default images of every notification that does not set its own (URLs or paths, e.g. '/icon.png'):
    // icon = picture next to the text, badge = small monochrome symbol in the Android status bar
    'icon'  => null,
    'badge' => null,

    // Channels subscribers can opt into (name => label for the UI); empty = any valid name accepted
    'channels' => [
        // 'alarms'  => 'Alarms',
        // 'reports' => 'Daily reports',
    ],

    // Channels a new subscription joins when the client does not send its own list
    'default_channels' => [],

    // Queue used by PushManager::queue() (processed by `php bin/frasm queue:work`)
    'queue' => 'default',

    // Validity of signed tokens for background POST action buttons (seconds)
    'action_token_ttl' => 604800,

    // Push services the server may contact (protects against SSRF via forged endpoints)
    'allowed_hosts' => [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        '*.push.services.mozilla.com',
        '*.push.apple.com',
        '*.notify.windows.com',
    ],

    // Service worker script (must be served from the web root to control the whole site)
    'service_worker' => '/frasm-sw.js',
];
