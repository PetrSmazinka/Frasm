<?php

declare(strict_types=1);

namespace Core\View\Live;

use Core\Controller\BaseController;
use Core\Exceptions\CoreException;
use Core\Routing\Attributes\Post;
use Core\View\Component;

/**
 * @file LiveComponentHandler.php
 * @brief Internal framework controller handling state synchronization and rendering for reactive components.
 */

/**
 * @class LiveComponentHandler
 * @brief System-level endpoint processing asynchronous requests emitted by the client-side live component driver.
 *
 * Deserializes incoming client state payloads, hydrates targeted component instances,
 * applies model updates, executes requested component actions, and returns updated HTML markup.
 */
class LiveComponentHandler extends BaseController
{
    /**
     * @brief Handles HTTP POST requests for component synchronization.
     *
     * Validates component existence and inheritance, rebuilds component state,
     * triggers specified actions, and renders replacement DOM nodes as a JSON payload.
     *
     * @return never Halts execution and emits JSON response directly via BaseController::json().
     * @throws CoreException If the request payload is malformed or targets an invalid component.
     */
    #[Post('/_frasm/live-component')]
    public function handle(): never
    {
        $payload = $this->jsonBody();

        if (!is_array($payload)) {
            throw new CoreException("Malformed JSON payload received for live component synchronization.", 400);
        }

        $componentClass = (string)($payload['component'] ?? '');
        $state = (array)($payload['state'] ?? []);
        $action = isset($payload['action']) ? (string)$payload['action'] : null;
        $updates = (array)($payload['updates'] ?? []);

        // Enforce strict security boundaries: target class must exist and derive from Core\View\Component
        if (!class_exists($componentClass) || !is_subclass_of($componentClass, Component::class)) {
            throw new CoreException("Invalid or unauthorized component target: '{$componentClass}'", 400);
        }

        /** @var Component $component */
        $component = new $componentClass();

        // 1. Rehydrate baseline state previously held by the client
        $component->hydrate($state);

        // 2. Apply incoming model data updates (e.g. from inputs with data-model bindings)
        if (!empty($updates)) {
            $component->hydrate($updates);
        }

        // 3. Invoke targeted action method if provided (e.g. from triggers with data-action bindings)
        if ($action !== null && $action !== '') {
            if (!method_exists($component, $action)) {
                throw new CoreException("Target action '{$action}' does not exist on component '{$componentClass}'.", 400);
            }

            $component->{$action}();
        }

        // 4. Return refreshed rendered HTML DOM block for client replacement
        $this->json([
            'html' => $component->render(),
        ]);
    }
}