/**
 * @file frasm-pwa.js
 * @brief Registers the Frasm service worker so the site can be installed as an app.
 *
 * Included by frasm_head() when `pwa.enabled` is on. The same service worker (frasm-sw.js) also
 * handles Web Push notifications. It is registered with the scope of the app the page belongs to
 * (meta frasm-sw-scope, e.g. "/smarthome"), so every installable app has its own registration.
 * Safe to include more than once.
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.pwa || !('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }
    Frasm.pwa = { registered: false };

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
    const register = () => {
        const base = meta('frasm-base').replace(/\/$/, '');
        navigator.serviceWorker.register(`${base}${meta('frasm-sw') || '/frasm-sw.js'}`, { scope: meta('frasm-sw-scope') || `${base}/` })
            .then(() => { Frasm.pwa.registered = true; })
            .catch((error) => console.warn('Frasm service worker registration failed:', error));
    };

    if (document.readyState === 'complete') {
        register();
    } else {
        window.addEventListener('load', register, { once: true });
    }
})();
