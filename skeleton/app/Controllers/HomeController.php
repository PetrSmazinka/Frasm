<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Components\Counter;
use Core\Controller\BaseController;
use Core\Routing\Attributes\Get;

/**
 * @file HomeController.php
 * @brief Hello world pages of the Frasm skeleton application.
 */
class HomeController extends BaseController
{
    /**
     * @brief Renders the welcome page with a live component.
     *
     * @return string Rendered HTML.
     */
    #[Get('/')]
    public function index(): string
    {
        $counter = new Counter();
        $counter->mount(0);

        return $this->view('home/index', [
            'pageTitle' => 'Hello, Frasm!',
            'counter'   => $counter->render(),
        ]);
    }

    /**
     * @brief Renders a second page to demonstrate navigation without full reloads.
     *
     * @return string Rendered HTML.
     */
    #[Get('/about')]
    public function about(): string
    {
        return $this->view('home/about', [
            'pageTitle' => 'About',
            'phpVersion' => PHP_VERSION,
        ]);
    }
}
