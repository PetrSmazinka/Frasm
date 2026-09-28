/**
 * @file frasm-push.js
 * @brief Browser side of Frasm Web Push: service worker registration and subscription management.
 *
 * API (window.Frasm.push):
 *   isSupported()      → boolean
 *   permission()       → 'default' | 'granted' | 'denied' | 'unsupported'
 *   subscribe({channels}) → Promise<boolean>  (call from a user gesture, e.g. a button click)
 *   channels()         → Promise<{channels: string[], available: object}|null>
 *   setChannels(list)  → Promise<string[]>
 *   unsubscribe()      → Promise<void>
 *   isSubscribed()     → Promise<boolean>
 *   sync()             → Promise<void>     (re-sends an existing subscription to the server)
 *
 * Reads configuration from meta tags emitted by frasm_head(): frasm-base, frasm-push-key,
 * frasm-push-sw and csrf-token. Existing subscriptions are synchronized once per browser session.
 * Push payloads forwarded by the service worker are dispatched as `frasm:push` events on document
 * (event.detail = payload), e.g. to refresh data when a data-only message arrives.
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
    const request = async (method, body, url = endpoint()) => {
        const response = await fetch(url, {
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
        return response.status === 204 ? null : response.json();
    };

    /**
     * @brief Waits until a registration has an active worker (navigator.serviceWorker.ready would wait
     *        for the registration controlling the page, which may be a different app's one).
     */
    const waitUntilActive = (reg) => new Promise((resolve) => {
        if (reg.active) {
            resolve(reg);
            return;
        }
        const worker = reg.installing || reg.waiting;
        worker?.addEventListener('statechange', () => {
            if (worker.state === 'activated') {
                resolve(reg);
            }
        });
    });

    const currentSubscription = async () => {
        const reg = await ownRegistration();
        return reg ? reg.pushManager.getSubscription() : null;
    };

    /**
     * @brief Scope of the service worker registration of this page's app (frasm-sw-scope, see frasm_head()).
     *        Each installable app has its own registration and therefore its own push subscription.
     */
    const scope = () => meta('frasm-sw-scope') || `${basePath()}/`;

    /**
     * @brief Returns the registration with exactly this app's scope (getRegistration() would also
     *        return a registration of an enclosing scope, i.e. another app's subscription).
     */
    const ownRegistration = async () => {
        const wanted = new URL(scope(), location.href).href;
        const registrations = await navigator.serviceWorker.getRegistrations();
        return registrations.find((reg) => reg.scope === wanted) || null;
    };

    /**
     * @brief Adds the app of this page (meta frasm-push-app) to a subscription, so the server can
     *        send notifications about a part of the site only to that part's app.
     */
    const withApp = (subscription) => ({ ...subscription, app: meta('frasm-push-app') });

    const registration = () =>
        navigator.serviceWorker.register(`${basePath()}${meta('frasm-push-sw') || '/frasm-sw.js'}`, { scope: scope() });

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

        async subscribe(options = {}) {
            if (!this.isSupported()) {
                return false;
            }

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return false;
            }

            const reg = await registration();
            await waitUntilActive(reg);

            let subscription = await reg.pushManager.getSubscription();
            if (!subscription) {
                subscription = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: decodeKey(await publicKey()),
                });
            }

            const body = withApp(subscription.toJSON());
            if (Array.isArray(options.channels)) {
                body.channels = options.channels;
            }
            await request('POST', body);
            return true;
        },

        async channels() {
            const subscription = this.isSupported() ? await currentSubscription() : null;
            if (!subscription) {
                return null;
            }

            const response = await fetch(
                `${basePath()}/_frasm/push/channels?endpoint=${encodeURIComponent(subscription.endpoint)}`,
                { credentials: 'same-origin', headers: { Accept: 'application/json' } }
            );
            return response.ok ? response.json() : null;
        },

        async setChannels(channels) {
            const subscription = this.isSupported() ? await currentSubscription() : null;
            if (!subscription) {
                throw new Error('Not subscribed to push notifications');
            }

            const result = await request('PUT', { endpoint: subscription.endpoint, channels }, `${basePath()}/_frasm/push/channels`);
            return result.channels;
        },

        async unsubscribe() {
            if (!this.isSupported()) {
                return;
            }

            const reg = await ownRegistration();
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

            const reg = await ownRegistration();
            return !!(reg && (await reg.pushManager.getSubscription()));
        },

        async sync() {
            if (this.permission() !== 'granted') {
                return;
            }

            const reg = await ownRegistration();
            const subscription = reg ? await reg.pushManager.getSubscription() : null;
            if (subscription) {
                await request('POST', withApp(subscription.toJSON()));
            }
        },
    };

    // Forward payloads relayed by the service worker to the page
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data?.type === 'frasm:push') {
                document.dispatchEvent(new CustomEvent('frasm:push', { detail: event.data.payload }));
            }
        });
    }

    // Keep the server copy fresh (user may have logged in on this device); once per browser session
    const autoSync = async () => {
        try {
            const reg = Frasm.push.permission() === 'granted'
                ? await ownRegistration()
                : null;
            const subscription = reg ? await reg.pushManager.getSubscription() : null;
            if (!subscription || sessionStorage.getItem('frasm-push-synced') === subscription.endpoint) {
                return;
            }
            await request('POST', withApp(subscription.toJSON()));
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
