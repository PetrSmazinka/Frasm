/**
 * @file frasm-sw.js
 * @brief Frasm service worker: displays Web Push notifications and opens their URL on click.
 *
 * Served from the web root so its scope covers the whole site. Applications with their own
 * service worker can reuse this logic via `importScripts('/frasm-sw.js')`.
 *
 * Payload (JSON, see Core\Push\PushMessage): title, body, url, icon, tag, data, plus any
 * Notification option (badge, image, requireInteraction, silent, ...).
 */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};
    if (event.data) {
        try {
            payload = event.data.json();
        } catch (e) {
            payload = { body: event.data.text() };
        }
    }

    const { title, url, data, ...options } = payload;
    options.data = { ...(data || {}), url: url || '/' };
    if (options.tag && options.renotify === undefined) {
        options.renotify = true;
    }

    event.waitUntil(self.registration.showNotification(title || 'Notification', options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = new URL(event.notification.data?.url || '/', self.location.origin);
    if (target.protocol !== 'https:' && target.protocol !== 'http:') {
        return;
    }

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Prefer focusing an already open tab of the same page, then any same-origin tab
        const exact = windows.find((client) => client.url === target.href);
        if (exact) {
            return exact.focus();
        }

        const sameOrigin = windows.find((client) => new URL(client.url).origin === target.origin);
        if (sameOrigin && target.origin === self.location.origin && 'navigate' in sameOrigin) {
            await sameOrigin.focus();
            return sameOrigin.navigate(target.href);
        }

        return self.clients.openWindow(target.href);
    })());
});
