<?php

declare(strict_types=1);

namespace App\Components;

use Core\View\Attributes\Locked;
use Core\View\Component;

/**
 * @file Counter.php
 * @brief Minimal live component: a counter with a server-side limit.
 */
class Counter extends Component
{
    /**
     * @var int Current value (synchronized with the browser).
     */
    public int $count = 0;

    /**
     * @var int Upper bound; #[Locked] prevents the browser from changing it.
     */
    #[Locked]
    public int $max = 10;

    /**
     * @brief Initializes the component when first rendered by a controller.
     *
     * @param int $start Initial value.
     * @return void
     */
    public function mount(int $start = 0): void
    {
        $this->count = min($start, $this->max);
    }

    /**
     * @brief Action bound to data-action="increment".
     *
     * @return void
     */
    public function increment(): void
    {
        $this->count = min($this->count + 1, $this->max);
    }

    /**
     * @brief Action bound to data-action="reset".
     *
     * @return void
     */
    public function reset(): void
    {
        $this->count = 0;
    }

    /**
     * @brief Returns the component template path.
     *
     * @return string
     */
    protected function template(): string
    {
        return FRASM_APP_DIR . '/Views/components/counter.php';
    }
}
