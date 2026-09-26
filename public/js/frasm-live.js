document.addEventListener('DOMContentLoaded', () => {
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
        const state = JSON.parse(container.getAttribute('data-frasm-state'));

        // Remember active input details for cursor restoration
        const activeId = triggerElement?.id || null;
        const activeName = triggerElement?.name || null;
        const cursorStart = triggerElement?.selectionStart ?? null;
        const cursorEnd = triggerElement?.selectionEnd ?? null;

        try {
            const response = await fetch('/_frasm/live-component', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ component, state, action, updates })
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
                const selector = activeId ? `#${activeId}` : `[name="${activeName}"]`;
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
});