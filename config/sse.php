<?php

declare(strict_types=1);

/**
 * Server-Sent Events (Response::eventStream())
 */
return [
    // Seconds a stream stays open before the browser transparently reconnects. Every open stream
    // occupies one Apache worker (mod_php), so keep this moderate on a Raspberry Pi.
    'max_duration' => 300,
];
