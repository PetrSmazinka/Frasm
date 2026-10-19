<?php

declare(strict_types=1);

namespace Core\Auth\Sso;

use Core\Exceptions\RouteNotFoundException;
use Core\Http\Request;
use Core\Http\Response;
use Core\RateLimit\RateLimiter;
use Core\Routing\Attributes\Get;
use Core\Routing\Attributes\Post;

/**
 * @file SsoClientController.php
 * @brief Redirect login of an SSO client (/_frasm/sso/login, /_frasm/sso/callback) and notifications of changed
 *        accounts (/_frasm/sso/changed), active when sso.client.enabled is true.
 */

/**
 * @class SsoClientController
 * @brief Starts the redirect to the SSO server and finishes it when the user comes back.
 *
 * The login page of the application links to /_frasm/sso/login?return=… (button "Sign in with …"), or redirects
 * to /_frasm/sso/login?silent=1&return=… to sign the user in without any form when they are signed in at the server
 * (SsoClient::canTrySilent() prevents a loop when they are not). The password form of the application calls
 * SsoClient::passwordLogin() itself. The server posts to /_frasm/sso/changed when an account changes (its client
 * config `notify`); the local copy is then read again from the server at once.
 */
class SsoClientController
{
    /**
     * @brief SsoClientController constructor.
     *
     * @param SsoClient $client SSO client.
     * @param RateLimiter $limiter Limit of the notifications.
     */
    public function __construct(private readonly SsoClient $client, private readonly RateLimiter $limiter)
    {
    }

    /**
     * @brief Redirects to the authorization page of the SSO server.
     *
     * @param Request $request Request.
     * @return Response
     */
    #[Get('/_frasm/sso/login')]
    public function login(Request $request): Response
    {
        $this->ensureEnabled();
        $silent = (bool)$request->query('silent', false);
        if ($silent && !$this->client->canTrySilent()) {
            $silent = false;
        }

        return Response::redirect($this->client->authorizeUrl((string)$request->query('return', '/'), $silent));
    }

    /**
     * @brief Finishes the sign-in and returns to the requested page.
     *
     * @param Request $request Request.
     * @return Response
     * @throws \Core\Exceptions\AuthException When the sign-in fails (rendered as an error page).
     */
    #[Get('/_frasm/sso/callback')]
    public function callback(Request $request): Response
    {
        $this->ensureEnabled();

        return Response::redirect($this->client->callback((array)$request->query()));
    }

    /**
     * @brief An account changed at the server: JSON {id} (its ID there). The local copy, if any, is read again from the
     *        server with this client's token – the message itself is trusted for nothing (no CSRF token, no
     *        authentication; at most 120 a minute per address).
     *
     * @param Request $request Request.
     * @return Response 204; 429 over the limit.
     */
    #[Post('/_frasm/sso/changed')]
    public function changed(Request $request): Response
    {
        $this->ensureEnabled();
        $key = 'sso-changed:' . $request->ip();
        if ($this->limiter->tooManyAttempts($key, 120)) {
            return Response::json(['error' => 'throttled'], 429);
        }
        $this->limiter->hit($key, 60);
        $id = (string)(($request->json() ?? [])['id'] ?? '');
        $localId = preg_match('/^[0-9A-Za-z_-]{1,64}$/D', $id) ? $this->client->localId($id) : null;
        if ($localId !== null) {
            $this->client->refresh($localId, true);
        }

        return new Response('', 204);
    }

    /**
     * @brief Hides the endpoints when this application is not an SSO client.
     *
     * @return void
     * @throws RouteNotFoundException
     */
    private function ensureEnabled(): void
    {
        if (!SsoClient::isEnabled()) {
            throw new RouteNotFoundException('Not Found');
        }
    }
}
