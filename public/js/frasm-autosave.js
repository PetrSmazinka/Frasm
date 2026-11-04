/**
 * @file frasm-autosave.js
 * @brief Forms that save themselves: form[data-frasm-autosave].
 *
 * A POST form with data-frasm-autosave is saved when a field changes (text fields: 0.8 s after typing stops; Enter
 * saves too). There is no button to press and the page stays as it is – no navigation, no scroll, no re-render, so
 * nothing jumps. The result is a toast (frasm-toast.js) or, in an element [data-frasm-autosave-status] inside the
 * form, a text. The server side is the action of the button: validate, save, flash(), redirect() –
 * AutosaveMiddleware answers a request with `X-Frasm-Autosave: 1` with {ok, message} and takes the flash message.
 *
 * Markup API:
 *   data-frasm-autosave                 on a POST form (="false": the form is not autosaved)
 *   data-frasm-autosave="false"         on a field or a button: it does not trigger a save / submits the form as usual
 *   [data-frasm-autosave-status]        inside the form: the result is written here instead of a toast
 * A button with a name, formaction or formmethod (e.g. "Delete") submits the form as usual, without a pending save.
 * Events: `frasm:autosaved` (bubbles from the form after a successful save).
 *
 * Works with or without frasm-nav.js (a pending save is sent before a navigation: frasm:before-visit, pagehide).
 * Needs `<meta name="csrf-token">` (frasm_head()).
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.autosave || !window.fetch) {
        return;
    }
    Frasm.autosave = true;

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
    const basePath = () => meta('frasm-base').replace(/\/$/, '');

    // A URL of this application: only to such a one the CSRF token is sent
    function isAppUrl(url) {
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) {
            return false;
        }
        const base = basePath();
        return !base || url.pathname === base || url.pathname.startsWith(`${base}/`);
    }

    const AUTOSAVE_DELAY_MS = 800;
    const AUTOSAVE_TEXT = 'input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([type=color]):not([type=hidden]), textarea';
    const autosaveState = new WeakMap();

    // The result of a save: in the form's own place, else a toast (a progress toast is replaced in place)
    function autosaveSay(form, text, failed) {
        const slot = form.querySelector('[data-frasm-autosave-status]');
        if (slot) {
            slot.textContent = text;
            slot.toggleAttribute('data-failed', !!failed);
        } else if (text === 'Ukládám…') {
            Frasm.toast?.(text, { type: 'info', key: 'frasm-autosave', duration: 0 });
        } else {
            Frasm.toast?.(text, { type: failed ? 'error' : 'success', key: 'frasm-autosave', duration: failed ? 7000 : 1800 });
        }
    }

    const autosaveStateOf = (form) => {
        let state = autosaveState.get(form);
        if (!state) {
            state = { timer: null, sending: false, again: false, dirty: false, url: null, quiet: false };
            autosaveState.set(form, state);
        }
        return state;
    };

    // The URL a form saves to: only one of this application (the CSRF token is sent with it), else null and the form
    // is submitted as usual. Taken while the page is shown: a save that runs after a navigation must not go to the
    // URL of the next page.
    function autosaveUrl(form) {
        const url = new URL(form.getAttribute('action') || location.href, location.href);
        url.hash = '';
        return isAppUrl(url) ? url : null;
    }

    // The autosave form a field belongs to (also by its form="…" attribute), or null
    function autosaveFormOf(field) {
        const form = field?.form instanceof HTMLFormElement ? field.form : field?.closest?.('form');
        return form && form.hasAttribute('data-frasm-autosave') && form.getAttribute('data-frasm-autosave') !== 'false' ? form : null;
    }

    async function autosaveSend(form) {
        const state = autosaveStateOf(form);
        clearTimeout(state.timer);
        state.timer = null;
        if (state.sending) {
            state.again = true;
            return;
        }
        // An incomplete form is not saved (and no bubble is opened under the user's hands); it is saved once it is valid
        if (!form.checkValidity()) {
            // While typing (the delayed save) a half-typed value is not reported; once the field is left, it is – with its own message
            if (!state.quiet) {
                autosaveSay(form, form.querySelector(':invalid')?.validationMessage || 'Vyplňte povinná pole', true);
            }
            return;
        }
        const url = state.url || autosaveUrl(form);
        if (!url) {
            return;
        }
        state.sending = true;
        state.again = false;
        state.dirty = false;
        autosaveSay(form, 'Ukládám…', false);
        window.Frasm?.nav?.clearCache?.();
        let message = null;
        try {
            // A redirect is not followed: AutosaveMiddleware answers the action's own redirect, so one reaching the
            // browser means the save did not get there (e.g. a sign-in page) – following it would look like success
            const response = await fetch(url.href, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'manual',
                headers: { Accept: 'application/json', 'X-Frasm-Autosave': '1', 'X-CSRF-TOKEN': meta('csrf-token') },
            });
            const payload = response.type === 'opaqueredirect' ? null : await response.json().catch(() => null);
            if (!response.ok || payload?.ok === false) {
                const errors = payload?.errors ? Object.values(payload.errors).flat() : [];
                message = errors[0] || payload?.message || 'Neuloženo';
            }
        } catch (e) {
            message = 'Neuloženo – chyba spojení';
        }
        state.sending = false;
        if (state.again) {
            // Changed while it was being saved: the newer values are sent (also after a failure – they may fix it)
            autosaveSend(form);
            return;
        }
        if (message !== null) {
            state.dirty = true;
            autosaveSay(form, String(message), true);
        } else {
            autosaveSay(form, 'Uloženo', false);
            form.dispatchEvent(new CustomEvent('frasm:autosaved', { bubbles: true }));
        }
    }

    function autosaveSchedule(form, immediately) {
        const state = autosaveStateOf(form);
        clearTimeout(state.timer);
        state.timer = null;
        state.dirty = true;
        state.url = autosaveUrl(form);
        state.quiet = !immediately;
        if (immediately) {
            autosaveSend(form);
        } else {
            state.timer = setTimeout(() => autosaveSend(form), AUTOSAVE_DELAY_MS);
        }
    }

    document.addEventListener('input', (event) => {
        const form = autosaveFormOf(event.target);
        if (form && event.target.matches?.(AUTOSAVE_TEXT) && !event.target.matches('[data-frasm-autosave="false"]') && autosaveUrl(form)) {
            autosaveSchedule(form, false);
        }
    });
    document.addEventListener('change', (event) => {
        const form = autosaveFormOf(event.target);
        if (form && !event.target.matches?.('[data-frasm-autosave="false"]') && autosaveUrl(form)) {
            autosaveSchedule(form, true);
        }
    });
    // Enter in a field, or a leftover button: the same save, not a navigation. A button of its own (a name, formaction,
    // formmethod, or data-frasm-autosave="false" – e.g. "Delete") submits the form as usual, without a pending save.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-frasm-autosave') || form.getAttribute('data-frasm-autosave') === 'false') {
            return;
        }
        const submitter = event.submitter;
        if (!autosaveUrl(form) || (submitter && (submitter.name || submitter.hasAttribute('formaction') || submitter.hasAttribute('formmethod')
            || submitter.getAttribute('data-frasm-autosave') === 'false'))) {
            const state = autosaveState.get(form);
            if (state) {
                clearTimeout(state.timer);
                state.timer = null;
                state.dirty = false;
            }
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        autosaveSchedule(form, true);
    }, true);
    // A navigation within the application: a pending save is sent now, to the URL of the page it was typed on
    document.addEventListener('frasm:before-visit', () => {
        document.querySelectorAll('form[data-frasm-autosave]').forEach((form) => {
            const state = autosaveState.get(form);
            if (state?.timer) {
                autosaveSend(form);
            }
        });
    });
    // Leaving the page with changes not saved yet: send them (keepalive survives the unload)
    window.addEventListener('pagehide', () => {
        document.querySelectorAll('form[data-frasm-autosave]').forEach((form) => {
            const state = autosaveState.get(form);
            const url = state?.url || autosaveUrl(form);
            if ((state?.dirty || state?.again) && url && form.checkValidity()) {
                clearTimeout(state.timer);
                state.timer = null;
                state.dirty = false;
                state.again = false;
                try {
                    fetch(url.href, {
                        method: 'POST', body: new URLSearchParams(Array.from(new FormData(form), ([k, v]) => [k, typeof v === 'string' ? v : ''])),
                        credentials: 'same-origin', keepalive: true, redirect: 'manual',
                        headers: { Accept: 'application/json', 'X-Frasm-Autosave': '1', 'X-CSRF-TOKEN': meta('csrf-token') },
                    });
                } catch (e) { /* the page is going away anyway */ }
            }
        });
    });
})();
