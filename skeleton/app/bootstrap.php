<?php

declare(strict_types=1);

/**
 * @file bootstrap.php
 * @brief Application service registrations (optional).
 *
 * Return a callable receiving the DI container; it runs after the framework bootstrap.
 */

use Core\Container\Container;

return function (Container $container): void {
    // $container->singleton(\App\Contracts\Mailer::class, \App\Services\SmtpMailer::class);
};
