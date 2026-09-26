/**
 * @file frasm-nav.js
 * @brief HTML-over-the-wire navigation: page transitions without full reloads (no white flash).
 *
 * Same-origin link clicks and form submissions are performed with fetch(); the server keeps
 * rendering ordinary full HTML pages. The response <body> replaces the current one, <head>
 * elements are merged (new stylesheets are loaded before the swap to avoid unstyled flashes),
 * history and scroll positions are maintained and inline/body scripts are executed.
 *
 * Markup API:
 *   data-frasm-nav="false"        on a link/form (or ancestor): use a regular browser navigation
 *   data-frasm-target="#id"       on a link/form: replace only the element #id with the same element
 *                                 from the response (URL/history unchanged unless data-frasm-history="true")
 *   data-frasm-permanent + id     element kept (not re-rendered) across navigations, e.g. a player
 *   data-frasm-track              on <script>/<link> in <head>: a changed URL forces a full reload (new deploy)
 *   data-frasm-prefetch="false"   disable hover prefetch for a link
 *   data-frasm-eval="false"       on a <script> in a swapped body: do not execute it
 *   <meta name="frasm-prefetch" content="off">        disable hover prefetch globally
 *   <meta name="frasm-view-transition" content="on">  animate swaps with the View Transitions API
 *
 * Requests carry `X-Frasm-Request: 1` (and `X-Frasm-Target` for fragment updates), available on the
 * server via Request::isFrasmRequest() / Request::frasmTarget().
 *
 * JS API (window.Frasm.nav): visit(url, options), prefetch(url), clearCache().
 * Events on document: frasm:before-visit (cancelable), frasm:before-render, frasm:load, frasm:frame-load.
 */
(() => {
    'use strict';

    const Frasm = (window.Frasm = window.Frasm || {});
    if (Frasm.nav || !window.fetch || !window.DOMParser || !window.history?.pushState) {
        return;
    }

    const PROGRESS_DELAY_MS = 120;
    const PREFETCH_TTL_MS = 10000;
    const PREFETCH_HOVER_MS = 65;
    const PREFETCH_MAX = 20;
    const STYLESHEET_TIMEOUT_MS = 3000;
    const EXECUTABLE_SCRIPT = /^(|module|text\/javascript|application\/javascript|text\/ecmascript|application\/ecmascript)$/i;

    /** @type {Map<string, {time: number, promise: Promise<object|null>}>} */
    const prefetchCache = new Map();
    let activeController = null;
    let renderedPageKey = pageKey(location);

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
    const basePath = () => meta('frasm-base').replace(/\/$/, '');

    /**
     * @brief Identifies a page irrespective of its hash.
     */
    function pageKey(url) {
        return url.pathname + url.search;
    }

    /**
     * @brief Dispatches a DOM event on document; returns false when a cancelable event was prevented.
     */
    function dispatch(name, detail, cancelable = false) {
        return document.dispatchEvent(new CustomEvent(name, { detail, cancelable, bubbles: true }));
    }

    /**
     * @brief Stores the current scroll position in the active history entry.
     */
    function saveScroll() {
        history.replaceState({ ...(history.state || {}), frasm: true, scrollX: window.scrollX, scrollY: window.scrollY }, '', location.href);
    }

    /**
     * @brief Checks whether a URL is a same-origin page inside the application.
     */
    function isAppUrl(url) {
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) {
            return false;
        }
        const base = basePath();
        return !base || url.pathname === base || url.pathname.startsWith(`${base}/`);
    }

    /**
     * @brief Decides whether a link click should be handled by Frasm navigation.
     */
    function linkEligible(link) {
        if (link.closest('[data-frasm-nav="false"]') || link.hasAttribute('download')) {
            return false;
        }

        const target = link.getAttribute('target');
        if ((target && target !== '_self') || (link.getAttribute('rel') || '').split(/\s+/).includes('external')) {
            return false;
        }

        const url = new URL(link.href, location.href);
        if (!isAppUrl(url)) {
            return false;
        }

        // Same-page anchors are left to the browser
        if (url.hash && pageKey(url) === pageKey(location)) {
            return false;
        }

        return !/\.(pdf|zip|gz|png|jpe?g|gif|svg|webp|avif|ico|mp3|mp4|webm|csv|xlsx?|docx?|txt|json|xml)$/i.test(url.pathname);
    }

    /**
     * @brief Decides whether a form submission should be handled by Frasm navigation.
     */
    function formEligible(form, submitter) {
        if (form.closest('[data-frasm-nav="false"]') || submitter?.getAttribute('data-frasm-nav') === 'false') {
            return false;
        }

        const target = submitter?.getAttribute('formtarget') || form.getAttribute('target');
        if (target && target !== '_self') {
            return false;
        }

        if (formMethod(form, submitter) === 'DIALOG') {
            return false;
        }

        return isAppUrl(formAction(form, submitter));
    }

    const formMethod = (form, submitter) =>
        (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toUpperCase();

    const formAction = (form, submitter) =>
        new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href, location.href);

    /**
     * @brief Performs the HTTP request and reads HTML responses.
     */
    async function fetchPage(url, method, body, target, signal, isPrefetch = false) {
        const headers = {
            'X-Frasm-Request': '1',
            Accept: 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1',
        };
        if (target) {
            headers['X-Frasm-Target'] = target;
        }
        if (isPrefetch) {
            headers['X-Frasm-Prefetch'] = '1';
        }

        const response = await fetch(url.href, { method, body, headers, credentials: 'same-origin', redirect: 'follow', signal });
        const type = response.headers.get('Content-Type') || '';
        const isAttachment = /attachment/i.test(response.headers.get('Content-Disposition') || '');
        const isHtml = /text\/html|application\/xhtml\+xml/i.test(type) && !isAttachment;

        return {
            response,
            url: new URL(response.url || url.href),
            redirected: response.redirected,
            status: response.status,
            isHtml,
            html: isHtml ? await response.text() : null,
        };
    }

    /**
     * @brief Shows a thin progress bar when a request takes longer than PROGRESS_DELAY_MS.
     */
    function startProgress() {
        let bar = null;
        const timer = setTimeout(() => {
            bar = document.createElement('div');
            bar.className = 'frasm-progress';
            document.documentElement.appendChild(bar);
            requestAnimationFrame(() => bar && bar.classList.add('frasm-progress--running'));
        }, PROGRESS_DELAY_MS);

        return {
            done() {
                clearTimeout(timer);
                if (bar) {
                    const current = bar;
                    current.classList.add('frasm-progress--done');
                    setTimeout(() => current.remove(), 350);
                }
            },
        };
    }

    /**
     * @brief Recreates a script element so the browser executes it.
     */
    function cloneScript(source) {
        const script = document.createElement('script');
        for (const { name, value } of Array.from(source.attributes)) {
            script.setAttribute(name, value);
        }
        script.textContent = source.textContent;
        script.async = source.hasAttribute('async');
        return script;
    }

    /**
     * @brief Executes scripts contained in freshly inserted markup.
     */
    function activateScripts(root) {
        for (const script of Array.from(root.querySelectorAll('script'))) {
            if (script.getAttribute('data-frasm-eval') === 'false' || !EXECUTABLE_SCRIPT.test(script.type || '')) {
                continue;
            }
            script.replaceWith(cloneScript(script));
        }
    }

    /**
     * @brief Returns URLs of tracked head assets (a change means a new deployment).
     */
    function trackedAssets(head) {
        return Array.from(head.querySelectorAll('[data-frasm-track]'))
            .map((el) => el.getAttribute('src') || el.getAttribute('href') || '')
            .sort()
            .join('|');
    }

    /**
     * @brief Resolves when a stylesheet link has loaded (or failed, or timed out).
     */
    function stylesheetLoaded(link) {
        return new Promise((resolve) => {
            const finish = () => resolve();
            link.addEventListener('load', finish, { once: true });
            link.addEventListener('error', finish, { once: true });
            setTimeout(finish, STYLESHEET_TIMEOUT_MS);
        });
    }

    /**
     * @brief Adds new head elements (awaiting stylesheets) and returns a callback removing stale ones.
     */
    async function mergeHead(newHead) {
        const key = (el) => el.outerHTML;
        const current = Array.from(document.head.children);
        const currentKeys = new Set(current.map(key));
        const incoming = Array.from(newHead.children);
        const incomingKeys = new Set(incoming.map(key));
        const pending = [];

        for (const element of incoming) {
            if (element.tagName === 'TITLE' || currentKeys.has(key(element))) {
                continue;
            }

            const node = element.tagName === 'SCRIPT' ? cloneScript(element) : document.importNode(element, true);
            if (node.tagName === 'LINK' && /(^|\s)stylesheet(\s|$)/i.test(node.getAttribute('rel') || '')) {
                pending.push(stylesheetLoaded(node));
            }
            document.head.appendChild(node);
        }

        await Promise.all(pending);

        return () => {
            for (const element of current) {
                // Executed scripts stay (removing them has no effect and keeps them from running twice)
                if (!incomingKeys.has(key(element)) && element.tagName !== 'SCRIPT' && element.tagName !== 'TITLE'
                    && !element.hasAttribute('data-frasm-permanent')) {
                    element.remove();
                }
            }
        };
    }

    /**
     * @brief Moves permanent elements of the current page into the incoming body.
     */
    function keepPermanentElements(newBody) {
        for (const element of Array.from(document.body.querySelectorAll('[data-frasm-permanent][id]'))) {
            const placeholder = newBody.querySelector(`#${CSS.escape(element.id)}`);
            if (placeholder) {
                placeholder.replaceWith(element);
            }
        }
    }

    /**
     * @brief Runs a DOM update, optionally inside a view transition.
     */
    async function withTransition(update) {
        const enabled = meta('frasm-view-transition') === 'on'
            && typeof document.startViewTransition === 'function'
            && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!enabled) {
            await update();
            return;
        }

        await document.startViewTransition(update).updateCallbackDone;
    }

    /**
     * @brief Replaces the document with a parsed response document.
     */
    async function renderPage(doc) {
        const removeStale = await mergeHead(doc.head);

        const newBody = document.adoptNode(doc.body);
        dispatch('frasm:before-render', { newBody });
        keepPermanentElements(newBody);

        await withTransition(() => {
            document.title = doc.title;
            for (const attribute of ['lang', 'dir']) {
                const value = doc.documentElement.getAttribute(attribute);
                if (value === null) {
                    document.documentElement.removeAttribute(attribute);
                } else {
                    document.documentElement.setAttribute(attribute, value);
                }
            }
            document.body.replaceWith(newBody);
            activateScripts(newBody);
        });

        removeStale();
    }

    /**
     * @brief Scrolls to the restored position, the URL fragment, or the top.
     */
    function applyScroll(url, scroll) {
        if (scroll) {
            window.scrollTo(scroll.x, scroll.y);
            return;
        }

        if (url.hash) {
            const anchor = document.getElementById(decodeURIComponent(url.hash.slice(1)));
            if (anchor) {
                anchor.scrollIntoView();
                return;
            }
        }

        window.scrollTo(0, 0);
    }

    /**
     * @brief Offers a non-HTML POST response (e.g. a generated export) as a download without resubmitting.
     */
    async function saveDownload(response) {
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = /filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i.exec(disposition);
        const name = match ? decodeURIComponent(match[1] || match[2]) : 'download';

        const href = URL.createObjectURL(await response.blob());
        const link = Object.assign(document.createElement('a'), { href, download: name });
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(href), 1000);
    }

    /**
     * @brief Returns a still valid prefetched page and removes it from the cache.
     */
    function takePrefetched(url) {
        const key = url.href.split('#')[0];
        const hit = prefetchCache.get(key);
        prefetchCache.delete(key);
        return hit && Date.now() - hit.time < PREFETCH_TTL_MS ? hit.promise : null;
    }

    /**
     * @brief Navigates to a URL.
     *
     * @param {string|URL} input Target URL.
     * @param {{action?: 'push'|'replace'|'restore', method?: string, body?: BodyInit|null,
     *          target?: string|null, history?: boolean, scroll?: {x: number, y: number}|null}} options
     */
    async function visit(input, options = {}) {
        const url = new URL(input, location.href);
        const method = (options.method || 'GET').toUpperCase();
        const action = options.action || 'push';
        const target = options.target || null;

        if (!dispatch('frasm:before-visit', { url: url.href, method, target }, true)) {
            return;
        }

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;

        if (action !== 'restore') {
            saveScroll();
        }

        const progress = startProgress();
        document.documentElement.setAttribute('aria-busy', 'true');

        try {
            const prefetched = method === 'GET' && !target ? takePrefetched(url) : null;
            let page = prefetched ? await prefetched : null;
            if (!page) {
                page = await fetchPage(url, method, options.body || null, target, controller.signal);
            }
            if (controller.signal.aborted) {
                return;
            }

            const finalUrl = page.url;
            finalUrl.hash = finalUrl.hash || (page.redirected ? '' : url.hash);

            if (!isAppUrl(finalUrl)) {
                window.location.assign(finalUrl.href);
                return;
            }

            if (!page.isHtml) {
                if (page.status === 204) {
                    return;
                }
                if (method === 'GET') {
                    window.location.assign(finalUrl.href);
                } else {
                    await saveDownload(page.response);
                }
                return;
            }

            const doc = new DOMParser().parseFromString(page.html, 'text/html');

            // Fragment update
            if (target) {
                const current = document.querySelector(target);
                const incoming = doc.querySelector(target);
                if (current && incoming) {
                    if (options.history && (method === 'GET' || page.redirected)) {
                        history.pushState({ frasm: true, scrollX: window.scrollX, scrollY: window.scrollY }, '', finalUrl.href);
                        renderedPageKey = pageKey(finalUrl);
                    }
                    const fragment = document.adoptNode(incoming);
                    await withTransition(() => {
                        current.replaceWith(fragment);
                        activateScripts(fragment);
                    });
                    dispatch('frasm:frame-load', { url: finalUrl.href, target, element: fragment });
                    return;
                }
            }

            // A new deployment changed tracked assets: a fresh document is needed
            if (method === 'GET' && trackedAssets(doc.head) !== trackedAssets(document.head)) {
                window.location.assign(finalUrl.href);
                return;
            }

            // Update the URL before inserting the new body so relative URLs resolve correctly
            const changesUrl = method === 'GET' || page.redirected;
            if (action === 'push' && changesUrl) {
                if (finalUrl.href === location.href) {
                    history.replaceState({ frasm: true, scrollX: 0, scrollY: 0 }, '', finalUrl.href);
                } else {
                    history.pushState({ frasm: true, scrollX: 0, scrollY: 0 }, '', finalUrl.href);
                }
            } else if (action === 'replace' && changesUrl) {
                history.replaceState({ frasm: true, scrollX: 0, scrollY: 0 }, '', finalUrl.href);
            }

            await renderPage(doc);
            renderedPageKey = pageKey(location);

            applyScroll(finalUrl, action === 'restore' ? options.scroll : null);
            const autofocus = document.body.querySelector('[autofocus]');
            if (autofocus) {
                autofocus.focus();
            }

            dispatch('frasm:load', { url: location.href });
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Frasm navigation failed, falling back to a full page load:', error);
            if (method === 'GET') {
                window.location.assign(url.href);
            }
        } finally {
            if (activeController === controller) {
                activeController = null;
                document.documentElement.removeAttribute('aria-busy');
            }
            progress.done();
        }
    }

    /**
     * @brief Submits a form through Frasm navigation.
     */
    async function submitForm(form, submitter) {
        const method = formMethod(form, submitter);
        const action = formAction(form, submitter);
        const target = submitter?.getAttribute('data-frasm-target') || form.getAttribute('data-frasm-target');
        const keepHistory = (form.getAttribute('data-frasm-history') || submitter?.getAttribute('data-frasm-history')) === 'true';

        let data;
        try {
            data = new FormData(form, submitter);
        } catch (e) {
            data = new FormData(form);
            if (submitter?.name) {
                data.append(submitter.name, submitter.value);
            }
        }

        if (method === 'GET') {
            const query = new URLSearchParams(Array.from(data, ([key, value]) => [key, typeof value === 'string' ? value : value.name]));
            action.search = query.toString();
            await visit(action, { target, history: keepHistory });
            return;
        }

        const enctype = (submitter?.getAttribute('formenctype') || form.enctype || '').toLowerCase();
        const body = enctype === 'multipart/form-data'
            ? data
            : new URLSearchParams(Array.from(data, ([key, value]) => [key, typeof value === 'string' ? value : value.name]));

        // Data may change on the server: prefetched pages are stale now
        prefetchCache.clear();

        form.setAttribute('aria-busy', 'true');
        const wasDisabled = submitter ? submitter.disabled : false;
        if (submitter) {
            submitter.disabled = true;
        }

        try {
            await visit(action, { method, body, target, history: keepHistory });
        } finally {
            form.removeAttribute('aria-busy');
            if (submitter) {
                submitter.disabled = wasDisabled;
            }
        }
    }

    /**
     * @brief Starts a background request for a link target (hover/touch prefetch).
     */
    function prefetch(input) {
        const url = new URL(input, location.href);
        const key = url.href.split('#')[0];
        const cached = prefetchCache.get(key);

        if (key === location.href.split('#')[0] || (cached && Date.now() - cached.time < PREFETCH_TTL_MS)) {
            return;
        }

        const promise = fetchPage(url, 'GET', null, null, undefined, true).catch(() => null);
        prefetchCache.set(key, { time: Date.now(), promise });

        while (prefetchCache.size > PREFETCH_MAX) {
            prefetchCache.delete(prefetchCache.keys().next().value);
        }
    }

    const prefetchAllowed = (link) => meta('frasm-prefetch') !== 'off'
        && link.getAttribute('data-frasm-prefetch') !== 'false'
        && !link.getAttribute('data-frasm-target')
        && linkEligible(link);

    // --- Styles of the progress bar -------------------------------------------------------------
    const style = document.createElement('style');
    style.textContent = '.frasm-progress{position:fixed;top:0;left:0;height:3px;width:0;z-index:2147483647;'
        + 'pointer-events:none;background:var(--frasm-progress-color,#6366f1);transition:width 8s cubic-bezier(.1,.7,.1,1),opacity .3s}'
        + '.frasm-progress--running{width:85%}.frasm-progress--done{width:100%;opacity:0;transition:width .2s,opacity .3s .1s}';
    document.head.appendChild(style);

    // --- Event wiring ---------------------------------------------------------------------------
    history.scrollRestoration = 'manual';
    history.replaceState({ ...(history.state || {}), frasm: true, scrollX: window.scrollX, scrollY: window.scrollY }, '', location.href);

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest?.('a[href]');
        if (!link || !linkEligible(link)) {
            return;
        }

        event.preventDefault();
        visit(link.href, {
            target: link.getAttribute('data-frasm-target'),
            history: link.getAttribute('data-frasm-history') === 'true',
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !formEligible(form, event.submitter)) {
            return;
        }

        event.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') {
            return; // Double submission guard
        }
        submitForm(form, event.submitter);
    });

    let hoverTimer = null;
    document.addEventListener('mouseover', (event) => {
        const link = event.target.closest?.('a[href]');
        if (!link || !prefetchAllowed(link)) {
            return;
        }
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => prefetch(link.href), PREFETCH_HOVER_MS);
        link.addEventListener('mouseleave', () => clearTimeout(hoverTimer), { once: true });
    }, { passive: true });

    document.addEventListener('touchstart', (event) => {
        const link = event.target.closest?.('a[href]');
        if (link && prefetchAllowed(link)) {
            prefetch(link.href);
        }
    }, { passive: true });

    window.addEventListener('popstate', (event) => {
        if (!event.state?.frasm) {
            return;
        }

        // Only the fragment changed: the browser handles the scroll
        if (pageKey(location) === renderedPageKey) {
            return;
        }

        visit(location.href, {
            action: 'restore',
            scroll: { x: event.state.scrollX || 0, y: event.state.scrollY || 0 },
        });
    });

    Frasm.nav = {
        visit: (url, options = {}) => visit(url, options),
        prefetch,
        clearCache: () => prefetchCache.clear(),
    };

    // Single initialization hook for application scripts, fired for the first page too
    const initialLoad = () => dispatch('frasm:load', { url: location.href });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialLoad, { once: true });
    } else {
        initialLoad();
    }
})();
