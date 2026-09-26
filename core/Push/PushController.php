<?php

declare(strict_types=1);

namespace Core\Push;

use Core\Auth\Auth;
use Core\Config\Config;
use Core\Controller\BaseController;
use Core\Exceptions\AuthException;
use Core\Exceptions\RouteNotFoundException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Attributes\Delete;
use Core\Routing\Attributes\Get;
use Core\Routing\Attributes\Middleware;
use Core\Routing\Attributes\Post;

/**
 * @file PushController.php
 * @brief Internal endpoints used by public/js/frasm-push.js to manage browser subscriptions.
 */

/**
 * @class PushController
 * @brief Exposes the VAPID public key and stores/removes push subscriptions.
 *
 * All endpoints respond 404 while `push.enabled` is false. Write endpoints are protected by the
 * global CsrfMiddleware and throttled; with `push.require_auth` subscriptions are bound to the
 * authenticated user.
 */
class PushController extends BaseController
{
    /**
     * @brief PushController constructor.
     *
     * @param SubscriptionRepository $subscriptions Subscription storage.
     */
    public function __construct(protected SubscriptionRepository $subscriptions)
    {
    }

    /**
     * @brief Returns the VAPID public key.
     *
     * @return Response JSON {"publicKey": "..."}.
     * @throws RouteNotFoundException When push is disabled.
     */
    #[Get('/_frasm/push/key')]
    public function key(): Response
    {
        $this->ensureEnabled();

        return Response::json(['publicKey' => PushManager::publicKey()]);
    }

    /**
     * @brief Stores (or refreshes) the browser's subscription.
     *
     * @param Request $request Current request with PushSubscription JSON body.
     * @return Response 201 {"subscribed": true}.
     * @throws RouteNotFoundException When push is disabled.
     * @throws AuthException 401 when authentication is required.
     * @throws \Core\Exceptions\PushException 400 on invalid subscription data.
     */
    #[Post('/_frasm/push/subscriptions')]
    #[Middleware('throttle:20,60')]
    public function subscribe(Request $request): Response
    {
        $this->ensureEnabled();
        $userId = $this->resolveUserId();

        $subscription = Subscription::fromBrowser($request->json(), PushManager::allowedHosts());
        $this->subscriptions->save(
            $subscription,
            $userId,
            $request->header('User-Agent'),
            (int)Config::get('push.max_subscriptions_per_user', 10)
        );

        return Response::json(['subscribed' => true], 201);
    }

    /**
     * @brief Removes the browser's subscription.
     *
     * Authenticated users can only remove their own subscriptions.
     *
     * @param Request $request Current request with {"endpoint": "..."} body.
     * @return Response 204 No Content.
     * @throws RouteNotFoundException When push is disabled.
     * @throws AuthException 401 when authentication is required.
     */
    #[Delete('/_frasm/push/subscriptions')]
    #[Middleware('throttle:20,60')]
    public function unsubscribe(Request $request): Response
    {
        $this->ensureEnabled();
        $userId = $this->resolveUserId();

        $endpoint = $request->input('endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $this->subscriptions->deleteByEndpoint($endpoint, $userId);
        }

        return Response::noContent();
    }

    /**
     * @brief Hides the endpoints while push is disabled.
     *
     * @return void
     * @throws RouteNotFoundException When push is disabled.
     */
    protected function ensureEnabled(): void
    {
        if (!PushManager::isEnabled()) {
            throw new RouteNotFoundException('Web Push is disabled.', 404);
        }
    }

    /**
     * @brief Returns the owning user id, enforcing authentication when configured.
     *
     * @return int|string|null
     * @throws AuthException 401 when authentication is required but missing.
     */
    protected function resolveUserId(): int|string|null
    {
        $userId = Auth::check() && !Auth::isStateless() ? Auth::id() : null;

        if ($userId === null && (bool)Config::get('push.require_auth', true)) {
            throw new AuthException('Authentication is required to manage push subscriptions.', 401);
        }

        return $userId;
    }
}
