/**
 * @file frasm-stream.js
 * @brief Server-Sent Events client applying HTML fragment updates (HTML-over-the-wire streaming).
 *
 * Markup: <div data-frasm-stream="/sensors/stream"> … </div>
 * While such an element is in the document, an EventSource is kept open to its URL. Events named
 * `frasm:html` ({target, action, html}, see Core\Http\EventStream::html()) update the DOM:
 * replace (outerHTML), update (innerHTML), append, prepend, remove. Targets are resolved inside
 * the streaming element first, then in the whole document.
 *
 * Every event (any name) is also dispatched on the element as `frasm:stream`
 * (detail: {event, data, id}). Connections follow frasm-nav.js page swaps automatically.
 * JS API: window.Frasm.stream.refresh() re-scans the document.
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.stream || !window.EventSource) {
        return;
    }

    /** @type {Map<Element, EventSource>} */
    const sources = new Map();

    /**
     * @brief Applies an HTML fragment update.
     */
    function applyHtml(root, message) {
        if (!message || typeof message.target !== 'string') {
            return;
        }

        const target = root.querySelector(message.target) || document.querySelector(message.target);
        if (!target) {
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = message.html || '';

        switch (message.action) {
            case 'update':
                target.replaceChildren(template.content);
                break;
            case 'append':
                target.append(template.content);
                break;
            case 'prepend':
                target.prepend(template.content);
                break;
            case 'remove':
                target.remove();
                break;
            default:
                target.replaceWith(template.content);
        }
    }

    /**
     * @brief Opens an EventSource for an element.
     */
    function connect(element) {
        const url = element.getAttribute('data-frasm-stream');
        if (!url) {
            return;
        }

        const source = new EventSource(new URL(url, location.href).href, { withCredentials: false });
        const forward = (event) => {
            let data = event.data;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                // Plain text payload
            }
            element.dispatchEvent(new CustomEvent('frasm:stream', {
                bubbles: true,
                detail: { event: event.type, data, id: event.lastEventId },
            }));
            return data;
        };

        source.addEventListener('frasm:html', (event) => applyHtml(element, forward(event)));
        source.addEventListener('message', forward);
        source.addEventListener('frasm:error', forward);

        sources.set(element, source);
    }

    /**
     * @brief Closes streams of removed elements and opens streams for new ones.
     */
    function refresh() {
        for (const [element, source] of sources) {
            if (!element.isConnected) {
                source.close();
                sources.delete(element);
            }
        }

        for (const element of document.querySelectorAll('[data-frasm-stream]')) {
            if (!sources.has(element)) {
                connect(element);
            }
        }
    }

    Frasm.stream = { refresh };

    document.addEventListener('frasm:load', refresh);
    document.addEventListener('frasm:frame-load', refresh);
    window.addEventListener('pagehide', () => {
        for (const source of sources.values()) {
            source.close();
        }
        sources.clear();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh, { once: true });
    } else {
        refresh();
    }
})();
