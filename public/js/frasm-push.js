/**
 * @file frasm-push.js
 * @brief Browser side of Frasm Web Push: service worker registration and subscription management.
 *
 * API (window.Frasm.push):
 *   isSupported()      → boolean
 *   permission()       → 'default' | 'granted' | 'denied' | 'unsupported'
 *   subscribe()        → Promise<boolean>  (call from a user gesture, e.g. a button click)
 *   unsubscribe()      → Promise<void>
 *   isSubscribed()     → Promise<boolean>
 *   sync()             → Promise<void>     (re-sends an existing subscription to the server)
 *
 * Reads configuration from meta tags emitted by frasm_head(): frasm-base, frasm-push-key,
 * frasm-push-sw and csrf-token. Existing subscriptions are synchronized on every page load.
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.push) {
        return;
    }

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
    const basePath = () => meta('frasm-base').replace(/\/$/, '');
    const endpoint = () => `${basePath()}/_frasm/push/subscriptions`;

    /**
     * @brief Converts a base64url string into a Uint8Array (applicationServerKey).
     */
    const decodeKey = (value) => {
        const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    };

    /**
     * @brief Sends a JSON request to the Frasm push endpoints with the CSRF token.
     */
    const request = async (method, body) => {
        const response = await fetch(endpoint(), {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': meta('csrf-token'),
            },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            throw new Error(`Frasm push request failed with HTTP ${response.status}`);
        }
    };

    const registration = () =>
        navigator.serviceWorker.register(`${basePath()}${meta('frasm-push-sw') || '/frasm-sw.js'}`);

    const publicKey = async () => {
        const fromMeta = meta('frasm-push-key');
        if (fromMeta) {
            return fromMeta;
        }

        const response = await fetch(`${basePath()}/_frasm/push/key`, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            throw new Error('Web Push is not enabled on the server');
        }
        return (await response.json()).publicKey;
    };

    Frasm.push = {
        isSupported() {
            return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
        },

        permission() {
            return this.isSupported() ? Notification.permission : 'unsupported';
        },

        async subscribe() {
            if (!this.isSupported()) {
                return false;
            }

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return false;
            }

            const reg = await registration();
            await navigator.serviceWorker.ready;

            let subscription = await reg.pushManager.getSubscription();
            if (!subscription) {
                subscription = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: decodeKey(await publicKey()),
                });
            }

            await request('POST', subscription.toJSON());
            return true;
        },

        async unsubscribe() {
            if (!this.isSupported()) {
                return;
            }

            const reg = await navigator.serviceWorker.getRegistration(`${basePath()}/`);
            const subscription = reg ? await reg.pushManager.getSubscription() : null;
            if (!subscription) {
                return;
            }

            try {
                await request('DELETE', { endpoint: subscription.endpoint });
            } finally {
                await subscription.unsubscribe();
            }
        },

        async isSubscribed() {
            if (!this.isSupported()) {
                return false;
            }

            const reg = await navigator.serviceWorker.getRegistration(`${basePath()}/`);
            return !!(reg && (await reg.pushManager.getSubscription()));
        },

        async sync() {
            if (this.permission() !== 'granted') {
                return;
            }

            const reg = await navigator.serviceWorker.getRegistration(`${basePath()}/`);
            const subscription = reg ? await reg.pushManager.getSubscription() : null;
            if (subscription) {
                await request('POST', subscription.toJSON());
            }
        },
    };

    // Keep the server copy fresh (user may have logged in on this device); once per browser session
    const autoSync = async () => {
        try {
            const reg = Frasm.push.permission() === 'granted'
                ? await navigator.serviceWorker.getRegistration(`${basePath()}/`)
                : null;
            const subscription = reg ? await reg.pushManager.getSubscription() : null;
            if (!subscription || sessionStorage.getItem('frasm-push-synced') === subscription.endpoint) {
                return;
            }
            await request('POST', subscription.toJSON());
            sessionStorage.setItem('frasm-push-synced', subscription.endpoint);
        } catch (e) {
            // Not logged in, push disabled or storage unavailable: retry on the next page load
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoSync, { once: true });
    } else {
        autoSync();
    }
})();
