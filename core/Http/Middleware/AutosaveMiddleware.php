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
 * messages this request set are taken from the session (all of them, whatever their type), so they do not appear on
 * the next page; flashes left by earlier requests stay for the page that shows them. An `error` flash means the save
 * failed; the message is the `error`, `success` or `info` flash (in this order), or another one set by the request.
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
        if ($request->header('X-Frasm-Autosave') !== '1') {
            return $next->handle($request);
        }

        $before = Session::flashes();
        $response = $next->handle($request);
        if ($response->getStatusCode() < 300 || $response->getStatusCode() > 399) {
            return $response;
        }

        // Only the flashes of this request (new or changed): an earlier one still waits for its page
        $flashes = [];
        foreach (Session::flashes() as $key => $value) {
            if (!array_key_exists($key, $before) || $before[$key] !== $value) {
                $flashes[(string)$key] = Session::getFlash((string)$key);
            }
        }

        $failed = isset($flashes['error']);
        $message = null;
        foreach ([...['error', 'success', 'info'], ...array_keys($flashes)] as $key) {
            if (is_string($flashes[$key] ?? null) && $flashes[$key] !== '') {
                $message = $flashes[$key];
                break;
            }
        }

        return Response::json(['ok' => !$failed, 'message' => $message], 200, ['Cache-Control' => 'no-store']);
    }
}
