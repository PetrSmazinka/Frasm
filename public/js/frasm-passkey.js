/**
 * @file frasm-passkey.js
 * @brief Browser side of Frasm passkeys (WebAuthn): sign-in with a fingerprint, a face or the PIN of the device.
 *
 * API (window.Frasm.passkey):
 *   isSupported()                 → boolean
 *   register(name)                → Promise<number>  passkey ID (signed-in user; call from a user gesture)
 *   login({remember, returnTo})   → Promise<string>  address to go to after the sign-in
 *   remove(id)                    → Promise<void>
 *
 * Errors are Error objects with `code`: 'cancelled' (the user closed the dialog), 'unsupported', or the error of the
 * server ('signature', 'unknown', 'no_access', 'exists', …). Endpoints: Core\Auth\PasskeyController; CSRF token from the
 * csrf-token meta tag (frasm_head()).
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.passkey) {
        return;
    }

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
    const url = (path) => `${meta('frasm-base').replace(/\/$/, '')}/_frasm/passkeys${path}`;

    const toBytes = (value) => {
        const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    };
    const toText = (buffer) => {
        if (!buffer) {
            return null;
        }
        let binary = '';
        new Uint8Array(buffer).forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };
    const failure = (code) => Object.assign(new Error(code), { code });

    /**
     * @brief Calls an endpoint with JSON.
     */
    const post = async (path, body = {}) => {
        const response = await fetch(url(path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': meta('csrf-token') },
            body: JSON.stringify(body),
        });
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }
        if (!response.ok) {
            throw failure(data?.error || (response.status === 429 ? 'throttled' : 'server'));
        }
        return data;
    };

    /**
     * @brief Runs a ceremony of the browser; closing the dialog is 'cancelled'.
     */
    const ceremony = async (call) => {
        try {
            return await call();
        } catch (error) {
            if (error?.name === 'NotAllowedError' || error?.name === 'AbortError') {
                throw failure('cancelled');
            }
            if (error?.name === 'InvalidStateError') {
                throw failure('exists');
            }
            throw failure(error?.code || 'unsupported');
        }
    };

    Frasm.passkey = {
        isSupported: () => Boolean(window.PublicKeyCredential && navigator.credentials && window.isSecureContext),

        async register(name) {
            if (!this.isSupported()) {
                throw failure('unsupported');
            }
            const options = await post('/register/options');
            const credential = await ceremony(() => navigator.credentials.create({
                publicKey: {
                    ...options,
                    challenge: toBytes(options.challenge),
                    user: { ...options.user, id: toBytes(options.user.id) },
                    excludeCredentials: (options.excludeCredentials || []).map((item) => ({ ...item, id: toBytes(item.id) })),
                },
            }));
            const result = await post('/register', {
                name,
                credential: {
                    rawId: toText(credential.rawId),
                    response: {
                        clientDataJSON: toText(credential.response.clientDataJSON),
                        attestationObject: toText(credential.response.attestationObject),
                        transports: credential.response.getTransports ? credential.response.getTransports() : [],
                    },
                },
            });
            return result.id;
        },

        async login({ remember = false, returnTo = null } = {}) {
            if (!this.isSupported()) {
                throw failure('unsupported');
            }
            const options = await post('/login/options');
            const credential = await ceremony(() => navigator.credentials.get({
                publicKey: { ...options, challenge: toBytes(options.challenge) },
            }));
            const result = await post('/login', {
                remember,
                return: returnTo,
                credential: {
                    rawId: toText(credential.rawId),
                    response: {
                        clientDataJSON: toText(credential.response.clientDataJSON),
                        authenticatorData: toText(credential.response.authenticatorData),
                        signature: toText(credential.response.signature),
                        userHandle: toText(credential.response.userHandle),
                    },
                },
            });
            return result.redirect;
        },

        async remove(id) {
            await post(`/${encodeURIComponent(String(id))}/delete`);
        },
    };
})();
