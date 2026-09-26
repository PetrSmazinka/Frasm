<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Core\Http\Response;
use RuntimeException;

/**
 * @file HttpResponseException.php
 * @brief Control-flow exception used to short-circuit request handling with a ready Response.
 */

/**
 * @class HttpResponseException
 * @brief Carries a finished Response out of deeply nested code (e.g. BaseController::redirect()).
 *
 * It intentionally does not extend FrasmException so that application code catching framework
 * errors does not swallow redirects. The middleware pipeline converts it back into a regular
 * Response, so outer middleware still sees and may decorate the response. It is never logged.
 */
final class HttpResponseException extends RuntimeException
{
    /**
     * @brief HttpResponseException constructor.
     *
     * @param Response $response Response to be returned to the client.
     */
    public function __construct(private readonly Response $response)
    {
        parent::__construct('HTTP response short-circuit.', $response->getStatusCode());
    }

    /**
     * @brief Returns the carried response.
     *
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }
}
