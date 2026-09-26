<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * @file RequestHandlerInterface.php
 * @brief Contract of a component that turns a Request into a Response (PSR-15 style).
 */

/**
 * @interface RequestHandlerInterface
 * @brief Handles a request and produces a response.
 */
interface RequestHandlerInterface
{
    /**
     * @brief Handles the request and returns a response.
     *
     * @param Request $request Incoming request.
     * @return Response
     */
    public function handle(Request $request): Response;
}
