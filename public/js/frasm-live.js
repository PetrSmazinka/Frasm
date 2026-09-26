document.addEventListener('DOMContentLoaded', () => {
    // Delegované sledování vstupů formulářů (data-model)
    document.addEventListener('input', (e) => {
        const modelProp = e.target.getAttribute('data-model');
        if (!modelProp) return;

        const container = e.target.closest('[data-frasm-component]');
        if (!container) return;

        // Debounce pro textové vyhledávání (300ms)
        clearTimeout(container._debounceTimer);
        container._debounceTimer = setTimeout(() => {
            syncComponent(container, null, { [modelProp]: e.target.value });
        }, 300);
    });

    // Delegované sledování kliknutí (data-action)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const container = btn.closest('[data-frasm-component]');
        if (!container) return;

        const action = btn.getAttribute('data-action');
        syncComponent(container, action, {});
    });

    async function syncComponent(container, action, updates) {
        const component = container.getAttribute('data-frasm-component');
        const state = JSON.parse(container.getAttribute('data-frasm-state'));

        const response = await fetch('/_frasm/live-component', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ component, state, action, updates })
        });

        if (response.ok) {
            const data = await response.json();
            container.outerHTML = data.html;
        } else {
            console.error('Component sync failed:', await response.text());
        }
    }
});