<?php

declare(strict_types=1);

namespace Core;

/**
 * @file Frasm.php
 * @brief Framework identity.
 */

/**
 * @class Frasm
 * @brief Holds the framework version.
 */
final class Frasm
{
    /**
     * @var string Semantic version of the framework.
     */
    public const VERSION = '0.1.0';

    /**
     * @brief Prevents instantiation.
     */
    private function __construct()
    {
    }
}
