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
class LiveComponentHandler extends BaseController
{
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

        if (!class_exists($componentClass) || !is_subclass_of($componentClass, Component::class)) {
            throw new CoreException("Invalid or unauthorized component target: '{$componentClass}'", 400);
        }

        /** @var Component $component */
        $component = new $componentClass();

        // 1. Silent hydration of previous state (no hooks fired)
        $component->hydrate($state, triggerHooks: false);

        // 2. Hydration of incoming inputs (fires updated() hooks)
        if (!empty($updates)) {
            $component->hydrate($updates, triggerHooks: true);
        }

        // 3. Execution of requested action
        if ($action !== null && $action !== '') {
            if (!method_exists($component, $action)) {
                throw new CoreException("Target action '{$action}' does not exist on component '{$componentClass}'.", 400);
            }

            $component->{$action}();
        }

        // 4. Return freshly re-rendered HTML
        $this->json([
            'html' => $component->render(),
        ]);
    }
}