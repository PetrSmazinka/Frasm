<?php

declare(strict_types=1);

/**
 * Database configuration
 */
return [
    'default' => 'mysql',

    'connections' => [
        'mysql' => [
            'host'     => 'localhost',
            'port'     => 3306,
            'database' => 'main',
            'username' => '',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
    ],
];