<?php

declare(strict_types=1);

namespace App\Tests;

use Core\Testing\TestCase;

/**
 * @file HomeTest.php
 * @brief Example tests of the Hello world pages – run them with `make test` (or `php bin/frasm test`).
 */
final class HomeTest extends TestCase
{
    /**
     * @brief The home page greets the visitor.
     *
     * @return void
     */
    public function testHomePage(): void
    {
        $this->http()->get('/')->assertOk()->assertSee('Hello, Frasm!');
    }

    /**
     * @brief The about page exists.
     *
     * @return void
     */
    public function testAboutPage(): void
    {
        $this->http()->get('/about')->assertOk()->assertSee('About');
    }

    /**
     * @brief Unknown pages answer 404.
     *
     * @return void
     */
    public function testUnknownPage(): void
    {
        $this->http()->get('/no-such-page')->assertStatus(404);
    }
}
