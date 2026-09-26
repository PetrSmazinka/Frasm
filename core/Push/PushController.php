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
use Core\Routing\Attributes\Put;

/**
 * @file PushController.php
 * @brief Internal endpoints used by public/js/frasm-push.js to manage browser subscriptions.
 */

/**
 * @class PushController
 * @brief Exposes the VAPID public key, stores/removes push subscriptions and manages their channels.
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
     * The body is PushSubscription.toJSON() optionally extended with "channels": [...]. Without it,
     * a new subscription joins `push.default_channels` and an existing one keeps its channels.
     *
     * @param Request $request Current request with PushSubscription JSON body.
     * @return Response 201 {"subscribed": true, "channels": [...]}.
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

        $body = $request->json();
        $subscription = Subscription::fromBrowser($body, PushManager::allowedHosts());
        $channels = is_array($body) && array_key_exists('channels', $body)
            ? PushManager::validateChannels($body['channels'])
            : null;

        $saved = $this->subscriptions->save(
            $subscription,
            $userId,
            $request->header('User-Agent'),
            (int)Config::get('push.max_subscriptions_per_user', 10)
        );

        if ($channels === null && $saved['created']) {
            $channels = PushManager::validateChannels((array)Config::get('push.default_channels', []));
        }
        if ($channels !== null) {
            $this->subscriptions->setChannels($saved['id'], $channels);
        }

        return Response::json(['subscribed' => true, 'channels' => $this->subscriptions->channelsOf($saved['id'])], 201);
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
     * @brief Returns the channels of the browser's subscription and the configured channel labels.
     *
     * @param Request $request Current request with ?endpoint=... query parameter.
     * @return Response JSON {"channels": [...], "available": {name: label}}.
     * @throws RouteNotFoundException When push is disabled or the subscription is unknown.
     * @throws AuthException 401/403 when not allowed.
     */
    #[Get('/_frasm/push/channels')]
    public function channels(Request $request): Response
    {
        $this->ensureEnabled();
        $subscriptionId = $this->ownedSubscriptionId($request->query('endpoint'));

        return Response::json([
            'channels'  => $this->subscriptions->channelsOf($subscriptionId),
            'available' => (object)PushManager::channels(),
        ]);
    }

    /**
     * @brief Replaces the channels of the browser's subscription.
     *
     * @param Request $request Current request with {"endpoint": "...", "channels": [...]} body.
     * @return Response JSON {"channels": [...]}.
     * @throws RouteNotFoundException When push is disabled or the subscription is unknown.
     * @throws AuthException 401/403 when not allowed.
     * @throws \Core\Exceptions\PushException 400 on invalid channels.
     */
    #[Put('/_frasm/push/channels')]
    #[Middleware('throttle:20,60')]
    public function updateChannels(Request $request): Response
    {
        $this->ensureEnabled();
        $subscriptionId = $this->ownedSubscriptionId($request->input('endpoint'));

        $this->subscriptions->setChannels($subscriptionId, PushManager::validateChannels($request->input('channels', [])));

        return Response::json(['channels' => $this->subscriptions->channelsOf($subscriptionId)]);
    }

    /**
     * @brief Resolves a subscription by endpoint and verifies it belongs to the current user.
     *
     * @param mixed $endpoint Endpoint URL from the client.
     * @return int Subscription id.
     * @throws RouteNotFoundException When the subscription does not exist.
     * @throws AuthException 401 when authentication is required, 403 for a foreign subscription.
     */
    protected function ownedSubscriptionId(mixed $endpoint): int
    {
        $userId = $this->resolveUserId();
        $record = is_string($endpoint) && $endpoint !== '' ? $this->subscriptions->findByEndpoint($endpoint) : null;

        if ($record === null) {
            throw new RouteNotFoundException('Unknown push subscription.', 404);
        }
        if ($record['user_id'] !== null && (string)$record['user_id'] !== (string)$userId) {
            throw new AuthException('This push subscription belongs to another user.', 403);
        }

        return $record['id'];
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
