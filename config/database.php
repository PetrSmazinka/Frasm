<?php

declare(strict_types=1);

/**
 * Database configuration
 *
 * Every connection is a database with its own user (grant it rights on that database only).
 * The default connection holds the framework tables (frasm_*: users, tokens, queue, …); further
 * connections serve parts of the application, e.g. in config/local.php:
 *
 *   'connections' => [
 *       'mysql' => ['database' => 'frasm', 'username' => 'app_frasm', 'password' => '…'],
 *       'blog'  => ['database' => 'blog',  'username' => 'app_blog',  'password' => '…'],
 *   ],
 *
 * Code uses DB::connection('blog') (DB::getInstance() = the default connection). Migrations of a
 * connection live in database/migrations/<connection>/ (directly in database/migrations/ for the
 * default connection); `php bin/frasm db:migrate` runs all of them.
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