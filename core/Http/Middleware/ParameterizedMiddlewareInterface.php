<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

/**
 * @file ParameterizedMiddlewareInterface.php
 * @brief Contract of a middleware accepting string parameters from its specification (e.g. 'throttle:5,60').
 */

/**
 * @interface ParameterizedMiddlewareInterface
 * @brief Receives the comma-separated parameters that follow the colon in a middleware specification.
 */
interface ParameterizedMiddlewareInterface extends MiddlewareInterface
{
    /**
     * @brief Applies specification parameters to a freshly created middleware instance.
     *
     * @param list<string> $parameters Parameters in declaration order.
     * @return void
     * @throws \Core\Exceptions\CoreException If the parameters are invalid.
     */
    public function setParameters(array $parameters): void;
}
