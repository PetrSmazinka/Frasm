<?php

declare(strict_types=1);

/**
 * E-mail (Core\Mail\Mailer)
 *
 * Setup: set 'transport' => 'smtp' and the server below in config/local.php (keep the password there),
 * then check it with `php bin/frasm mail:test you@example.com`. Tests always use the 'array' transport.
 */
return [
    // smtp = send through the server below; log = only write to the log (nothing is sent); array = keep in memory
    'transport' => 'log',

    // Sender of all messages; an empty address = mail.smtp.username (most servers require the logged-in mailbox)
    'from' => [
        'address' => '',
        'name'    => '',
    ],

    'smtp' => [
        'host'       => '',
        // 465 with 'ssl' (TLS from the start), 587 with 'tls' (STARTTLS); 'none' only for a trusted local relay
        'port'       => 465,
        'encryption' => 'ssl',
        'username'   => '',
        'password'   => '',
        // Seconds for connecting and for every reply of the server
        'timeout'    => 15,
        // Host name announced in EHLO (empty = host name of this machine)
        'ehlo'       => '',
    ],
];
