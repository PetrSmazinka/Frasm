/**
 * @file frasm-toast.js
 * @brief Toasts: short messages in the corner of the page, instead of banners that push the content down.
 *
 * The server side stays what it was: a controller calls flash('success'|'error'|'info', …) and the layout prints them
 * with frasm_flash(). The printed messages are read by this script and shown as toasts (without it – or without
 * JavaScript at all – they stay a plain list on the page).
 *
 * JS API:
 *   Frasm.toast(text, { type: 'success'|'error'|'info', key?: string, duration?: ms (0 = until closed) })
 *     – a toast with the same key replaces the shown one in place (progress such as "Saving…" → "Saved").
 *   Frasm.toast.clear(key) – removes the toast with that key.
 * Markup API (printed by frasm_flash()): an element [data-frasm-flash] with children [data-type]; read once, on every
 * page (frasm:load, also after navigations of frasm-nav.js, and frasm:frame-load) and removed.
 *
 * Look: custom properties --frasm-toast-bg, --frasm-toast-color, --frasm-toast-radius, --frasm-toast-success,
 * --frasm-toast-error, --frasm-toast-info (set them on :root). A click closes a toast; the pointer over it holds the timer.
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.toast) {
        return;
    }

    const DURATION = { success: 4500, info: 4500, error: 9000 };
    const TYPES = { success: 'success', danger: 'error', error: 'error', info: 'info' };
    const MAX = 5;

    const style = document.createElement('style');
    // frasm-nav.js removes the head elements a new page does not have; this one is added by the script, not by the page
    style.setAttribute('data-frasm-permanent', '');
    style.textContent = '.frasm-toasts{position:fixed;right:16px;bottom:16px;z-index:2147483646;display:flex;flex-direction:column;'
        + 'gap:8px;width:min(380px,calc(100vw - 32px));pointer-events:none}'
        + '.frasm-toast{pointer-events:auto;cursor:pointer;padding:10px 14px;border-radius:var(--frasm-toast-radius,8px);'
        + 'border-left:4px solid var(--frasm-toast-info,#3b82f6);background:var(--frasm-toast-bg,#1f2937);'
        + 'color:var(--frasm-toast-color,#fff);font:14px/1.4 system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.28);'
        + 'overflow-wrap:anywhere;animation:frasm-toast-in .18s ease-out}'
        + '.frasm-toast[data-type=success]{border-left-color:var(--frasm-toast-success,#22c55e)}'
        + '.frasm-toast[data-type=error]{border-left-color:var(--frasm-toast-error,#ef4444)}'
        + '.frasm-toast--leaving{opacity:0;transform:translateY(6px);transition:opacity .18s,transform .18s}'
        + '@keyframes frasm-toast-in{from{opacity:0;transform:translateY(6px)}}'
        + '@media (prefers-reduced-motion:reduce){.frasm-toast{animation:none}.frasm-toast--leaving{transition:none}}';
    document.head.appendChild(style);

    let box = null;

    function container() {
        if (!box) {
            box = document.createElement('div');
            box.className = 'frasm-toasts';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
        }
        // A navigation replaced the body the container was in
        if (!box.isConnected) {
            document.body.appendChild(box);
        }
        return box;
    }

    function remove(element) {
        if (!element.isConnected || element.classList.contains('frasm-toast--leaving')) {
            return;
        }
        clearTimeout(element._frasmTimer);
        element.classList.add('frasm-toast--leaving');
        setTimeout(() => element.remove(), 200);
    }

    function arm(element, duration) {
        clearTimeout(element._frasmTimer);
        if (duration > 0) {
            element._frasmTimer = setTimeout(() => remove(element), duration);
        }
    }

    /**
     * @brief Shows a toast.
     */
    function toast(text, options = {}) {
        const message = String(text ?? '').trim();
        if (message === '') {
            return null;
        }
        const type = TYPES[options.type] || 'info';
        const duration = options.duration ?? DURATION[type];
        const parent = container();

        let element = options.key ? parent.querySelector(`.frasm-toast[data-key="${CSS.escape(options.key)}"]`) : null;
        if (!element) {
            element = document.createElement('div');
            element.className = 'frasm-toast';
            if (options.key) {
                element.dataset.key = options.key;
            }
            element.addEventListener('click', () => remove(element));
            element.addEventListener('mouseenter', () => clearTimeout(element._frasmTimer));
            element.addEventListener('mouseleave', () => arm(element, element._frasmDuration));
            parent.appendChild(element);
            while (parent.children.length > MAX) {
                parent.firstElementChild.remove();
            }
        }
        element.classList.remove('frasm-toast--leaving');
        element.dataset.type = type;
        element.textContent = message;
        element._frasmDuration = duration;
        arm(element, duration);

        return element;
    }

    toast.clear = (key) => {
        box?.querySelectorAll(`.frasm-toast[data-key="${CSS.escape(key)}"]`).forEach(remove);
    };

    Frasm.toast = toast;

    // Messages printed by the server (frasm_flash()) become toasts, once
    function fromPage() {
        document.querySelectorAll('[data-frasm-flash]').forEach((printed) => {
            printed.querySelectorAll('[data-type]').forEach((item) => toast(item.textContent, { type: item.getAttribute('data-type') }));
            printed.remove();
        });
    }

    document.addEventListener('frasm:load', fromPage);
    document.addEventListener('frasm:frame-load', fromPage);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fromPage, { once: true });
    } else {
        fromPage();
    }
})();
