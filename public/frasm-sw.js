/**
 * @file frasm-sw.js
 * @brief Frasm service worker: displays Web Push notifications and handles clicks and action buttons.
 *
 * Served from the web root so its scope covers the whole site. Applications with their own
 * service worker can reuse this logic via `importScripts('/frasm-sw.js')`.
 *
 * Payload (JSON, see Core\Push\PushMessage): title, body, url, icon, tag, data, actions,
 * frasmActions (per-action url or signed background POST), appBadge, dataOnly, plus any
 * Notification option (badge, image, requireInteraction, silent, ...).
 *
 * Every push is also forwarded to open tabs as a message {type: 'frasm:push', payload};
 * frasm-push.js re-dispatches it as a `frasm:push` DOM event.
 */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/**
 * @brief Parses the push payload (JSON, falling back to plain text).
 */
function readPayload(event) {
    if (!event.data) {
        return {};
    }
    try {
        return event.data.json();
    } catch (e) {
        return { body: event.data.text() };
    }
}

/**
 * @brief Updates the badge on the installed app icon when supported.
 */
async function updateAppBadge(count) {
    if (typeof count !== 'number' || !('setAppBadge' in self.navigator)) {
        return;
    }
    try {
        await (count > 0 ? self.navigator.setAppBadge(count) : self.navigator.clearAppBadge());
    } catch (e) {
        // Badging is best effort
    }
}

/**
 * @brief Resolves a same-origin URL; anything else is rejected.
 */
function sameOriginUrl(value) {
    try {
        const url = new URL(value || '/', self.location.origin);
        return url.origin === self.location.origin ? url : null;
    } catch (e) {
        return null;
    }
}

/**
 * @brief Focuses a tab showing the URL, navigates another same-origin tab, or opens a new window.
 */
async function openUrl(target) {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    const exact = windows.find((client) => client.url === target.href);
    if (exact) {
        return exact.focus();
    }

    const sameOrigin = windows.find((client) => new URL(client.url).origin === target.origin);
    if (sameOrigin && 'navigate' in sameOrigin) {
        await sameOrigin.focus();
        return sameOrigin.navigate(target.href);
    }

    return self.clients.openWindow(target.href);
}

/**
 * @brief Returns the application's custom notification data without framework internals.
 */
function customData(data) {
    const { frasmActions, ...custom } = data || {};
    return custom;
}

self.addEventListener('push', (event) => {
    const payload = readPayload(event);

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            client.postMessage({ type: 'frasm:push', payload });
        }

        await updateAppBadge(payload.appBadge);

        // Browsers require a visible notification for every push; data-only messages skip it only
        // when a tab of the site is visible and can react to the forwarded data itself
        if (payload.dataOnly && windows.some((client) => client.visibilityState === 'visible')) {
            return;
        }

        const { title, url, data, frasmActions, appBadge, dataOnly, ...options } = payload;
        options.data = { ...(data || {}), url: url || '/', frasmActions: frasmActions || {} };
        if (options.tag && options.renotify === undefined) {
            options.renotify = true;
        }

        await self.registration.showNotification(title || 'Notification', options);
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data = event.notification.data || {};
    const handler = event.action ? (data.frasmActions || {})[event.action] : null;

    event.waitUntil((async () => {
        // Background action: signed POST without opening the site
        if (handler && handler.post) {
            const target = sameOriginUrl(handler.post);
            if (!target) {
                return;
            }

            const response = await fetch(target.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Frasm-Push-Action': handler.token,
                },
                body: JSON.stringify({ action: event.action, data: customData(data) }),
            });

            if (!response.ok) {
                await self.registration.showNotification('Action failed', {
                    body: `The action could not be completed (HTTP ${response.status}).`,
                    data: { url: data.url || '/' },
                });
            }
            return;
        }

        const target = sameOriginUrl(handler && handler.url ? handler.url : data.url);
        if (target) {
            await openUrl(target);
        }
    })());
});
