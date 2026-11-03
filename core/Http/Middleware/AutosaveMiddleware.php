<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\RequestHandlerInterface;
use Core\Http\Response;
use Core\Session\Session;

/**
 * @file AutosaveMiddleware.php
 * @brief Answers the saves of forms with data-frasm-autosave (frasm-nav.js) with a short JSON instead of a redirect.
 */

/**
 * @class AutosaveMiddleware
 * @brief Turns "save, flash and redirect" of a controller into {ok, message} for a form that saves itself.
 *
 * The controller stays the same as for the button: it validates, saves, flashes and redirects. For a request with
 * `X-Frasm-Autosave: 1` the redirect is not followed (that would render a whole page nobody looks at) and the flash
 * message is taken from the session, so it does not appear on the next page: an `error` flash means the save failed.
 * Validation errors (422 JSON, the request asks for JSON) and other errors are left as they are.
 */
class AutosaveMiddleware implements MiddlewareInterface
{
    /**
     * @brief Replaces a redirect answering an autosave request.
     *
     * @param Request $request Incoming request.
     * @param RequestHandlerInterface $next Next handler.
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);
        if ($request->header('X-Frasm-Autosave') !== '1' || $response->getStatusCode() < 300 || $response->getStatusCode() > 399) {
            return $response;
        }

        $error = Session::getFlash('error');
        $message = $error ?? Session::getFlash('success');
        Session::getFlash('info');

        return Response::json(['ok' => $error === null, 'message' => is_string($message) ? $message : null], 200, ['Cache-Control' => 'no-store']);
    }
}
