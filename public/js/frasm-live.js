/**
 * @file frasm-live.js
 * @brief Client driver of Frasm live components (data-model, data-model.lazy, data-action).
 *
 * Uses document-level event delegation, so components inserted later (e.g. by frasm-nav.js
 * page swaps) work without re-initialization. Safe to include more than once.
 */
(() => {
    'use strict';

    if (window.Frasm?.live) {
        return;
    }
    window.Frasm = window.Frasm || {};
    window.Frasm.live = { version: 1 };

    const init = () => {
        // 1. Live binding (input event with debounce)
        document.addEventListener('input', (e) => {
            const modelProp = e.target.getAttribute('data-model');
            if (!modelProp) return;

            const container = e.target.closest('[data-frasm-component]');
            if (!container) return;

            const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;

            clearTimeout(container._debounceTimer);
            container._debounceTimer = setTimeout(() => {
                syncComponent(container, null, { [modelProp]: value }, e.target);
            }, 250);
        });

        // 2. Lazy binding (change event on blur / selection)
        document.addEventListener('change', (e) => {
            const modelProp = e.target.getAttribute('data-model.lazy');
            if (!modelProp) return;

            const container = e.target.closest('[data-frasm-component]');
            if (!container) return;

            const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
            syncComponent(container, null, { [modelProp]: value }, e.target);
        });

        // 3. Actions (click event)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;

            const container = btn.closest('[data-frasm-component]');
            if (!container) return;

            const action = btn.getAttribute('data-action');
            syncComponent(container, action, {}, btn);
        });

        async function syncComponent(container, action, updates, triggerElement) {
            const component = container.getAttribute('data-frasm-component');
            // Sent verbatim: the server verifies its HMAC checksum over the exact string
            const state = container.getAttribute('data-frasm-state');
            const checksum = container.getAttribute('data-frasm-checksum');
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = container.getAttribute('data-frasm-csrf') || (csrfMeta ? csrfMeta.getAttribute('content') : '');

            // Remember active input details for cursor restoration
            const activeId = triggerElement?.id || null;
            const activeName = triggerElement?.name || null;
            const cursorStart = triggerElement?.selectionStart ?? null;
            const cursorEnd = triggerElement?.selectionEnd ?? null;

            try {
                const basePath = (document.querySelector('meta[name="frasm-base"]')?.getAttribute('content') || '').replace(/\/$/, '');
                const response = await fetch(`${basePath}/_frasm/live-component`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ component, state, checksum, action, updates })
                });

                if (!response.ok) {
                    console.error('Component sync failed:', await response.text());
                    return;
                }

                const data = await response.json();

                // Replace DOM node
                const template = document.createElement('template');
                template.innerHTML = data.html.trim();
                const newElement = template.content.firstChild;

                container.replaceWith(newElement);

                // Restore focus and cursor position
                if (activeId || activeName) {
                    const selector = activeId ? `#${CSS.escape(activeId)}` : `[name="${CSS.escape(activeName)}"]`;
                    const restoredInput = newElement.querySelector(selector);
                    if (restoredInput && typeof restoredInput.focus === 'function') {
                        restoredInput.focus();
                        if (cursorStart !== null && cursorEnd !== null && typeof restoredInput.setSelectionRange === 'function') {
                            restoredInput.setSelectionRange(cursorStart, cursorEnd);
                        }
                    }
                }
            } catch (err) {
                console.error('Network error during sync:', err);
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
