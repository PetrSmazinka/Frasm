<?php

declare(strict_types=1);

namespace Core\View\Live;

use Core\Container\Container;
use Core\Controller\BaseController;
use Core\Exceptions\CoreException;
use Core\Http\Response;
use Core\Routing\Attributes\Post;
use Core\View\Component;
use ReflectionMethod;

/**
 * @file LiveComponentHandler.php
 * @brief Internal framework controller handling state synchronization and rendering for reactive components.
 */

/**
 * @class LiveComponentHandler
 * @brief Re-hydrates a component from the client payload, applies updates, runs an action and re-renders it.
 *
 * The endpoint is protected by the global CsrfMiddleware (frasm-live.js sends X-CSRF-TOKEN).
 * The previous state must carry a valid HMAC checksum (see Component::checksum()), so the client
 * cannot forge it; client updates may only target public properties not marked #[Locked].
 * Only public, non-static methods declared by the concrete component (not by Core\View\Component),
 * without required parameters and not being lifecycle hooks, may be invoked as actions.
 */
class LiveComponentHandler extends BaseController
{
    /**
     * @brief Synchronizes a component and returns its fresh HTML.
     *
     * @param Container $container Container used to instantiate the component.
     * @return Response JSON response {"html": "..."}.
     * @throws CoreException 400 on malformed payloads, unknown components or forbidden actions.
     */
    #[Post('/_frasm/live-component')]
    public function handle(Container $container): Response
    {
        $payload = $this->jsonBody();

        if (!is_array($payload)) {
            throw new CoreException("Malformed JSON payload received for live component synchronization.", 400);
        }

        $componentClass = is_string($payload['component'] ?? null) ? $payload['component'] : '';
        $stateJson = is_string($payload['state'] ?? null) ? $payload['state'] : '';
        $checksum = is_string($payload['checksum'] ?? null) ? $payload['checksum'] : '';
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : null;
        $updates = is_array($payload['updates'] ?? null) ? $payload['updates'] : [];

        if ($componentClass === '' || !class_exists($componentClass) || !is_subclass_of($componentClass, Component::class)) {
            throw new CoreException("Invalid or unauthorized component target: '{$componentClass}'", 400);
        }

        if (!hash_equals(Component::checksum($componentClass, $stateJson), $checksum)) {
            throw new CoreException("Component state checksum mismatch for '{$componentClass}' (tampered or stale state).", 400);
        }

        $state = json_decode($stateJson, true);
        if (!is_array($state)) {
            throw new CoreException("Malformed component state for '{$componentClass}'.", 400);
        }

        /** @var Component $component */
        $component = $container->make($componentClass);

        // 1. Silent hydration of previous state (no hooks fired)
        $component->hydrate($state, triggerHooks: false);

        // 2. Hydration of incoming inputs (fires updated() hooks)
        if (!empty($updates)) {
            $component->assertUpdatable($updates);
            $component->hydrate($updates, triggerHooks: true);
        }

        // 3. Execution of requested action
        if ($action !== null && $action !== '') {
            if (!$this->isCallableAction($component, $action)) {
                throw new CoreException("Action '{$action}' is not a callable action of component '{$componentClass}'.", 400);
            }

            $component->{$action}();
        }

        // 4. Return freshly re-rendered HTML
        return Response::json([
            'html' => $component->render(),
        ]);
    }

    /**
     * @brief Checks whether a method may be invoked from the client as a component action.
     *
     * @param Component $component Target component.
     * @param string $action Requested method name.
     * @return bool
     */
    protected function isCallableAction(Component $component, string $action): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $action) || !method_exists($component, $action)) {
            return false;
        }

        $method = new ReflectionMethod($component, $action);
        $declaringClass = $method->getDeclaringClass()->getName();

        return $method->isPublic()
            && !$method->isStatic()
            && !$method->isConstructor()
            && !$method->isDestructor()
            && $method->getNumberOfRequiredParameters() === 0
            && !str_starts_with($action, '__')
            && $action !== 'mount'
            && !str_starts_with($action, 'updated')
            && $declaringClass !== Component::class
            && is_subclass_of($declaringClass, Component::class);
    }
}
