<?php

declare(strict_types=1);

/**
 * @file helpers.php
 * @brief Global helper functions available in controllers and view templates.
 */

use Core\I18n\Lang;

if (!function_exists('__')) {
    /**
     * @brief Shortcut for translating a key via Core\I18n\Lang.
     *
     * @param string $key Key in the form 'file.item'.
     * @param array<string, scalar> $replace Placeholder values.
     * @return string Translated line or the key itself.
     */
    function __(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }
}

if (!function_exists('asset')) {
    /**
     * @brief Returns the URL of an application asset from app/Assets, versioned by its modification time.
     *
     * Example: <link rel="stylesheet" href="<?= asset('css/app.css') ?>"> gives
     * "/assets/css/app.css?v=6718a3f2". The version changes whenever the file changes, so browsers
     * may cache assets for a year (see Core\Http\AssetServer).
     *
     * @param string $path Path inside app/Assets.
     * @return string URL, HTML-safe (no characters that need escaping in an attribute).
     */
    function asset(string $path): string
    {
        $container = \Core\Container\Container::getInstance();
        $request = $container->bound(\Core\Http\Request::class)
            ? $container->get(\Core\Http\Request::class)
            : \Core\Http\Request::fromGlobals();

        return \Core\Http\AssetServer::url($path, $request->basePath(), FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Assets');
    }
}

if (!function_exists('url')) {
    /**
     * @brief Returns an absolute URL on the current host or on another domain of `app.domains`.
     *
     * url('/login') stays on the current host; url('/', 'smarthome') links to the smarthome domain and
     * url('/dashboard', 'tenant', ['tenant' => 'acme']) fills the {tenant} placeholder (missing values
     * are taken over from the current host). Scheme, port and base path follow the current request.
     *
     * @param string $path Path within the application, optionally with a query string.
     * @param string|null $domain Domain name, or null for the current host.
     * @param array<string, string|int> $params Placeholder values of the domain.
     * @return string
     * @throws \Core\Exceptions\CoreException On an unknown domain, a missing or invalid placeholder
     *                                         value, or a host not listed in app.domains.
     */
    function url(string $path = '/', ?string $domain = null, array $params = []): string
    {
        $container = \Core\Container\Container::getInstance();
        $request = $container->bound(\Core\Http\Request::class)
            ? $container->get(\Core\Http\Request::class)
            : \Core\Http\Request::fromGlobals();

        return $container->get(\Core\Routing\Domains::class)->url($request, $path, $domain, $params);
    }
}

if (!function_exists('frasm_head')) {
    /**
     * @brief Returns the framework <head> markup: base path, CSRF token, Web Push key and client scripts.
     *
     * Place it inside <head> of every layout. Scripts are versioned by modification time and marked
     * with data-frasm-track, so a deployment of new framework scripts forces a full page reload.
     *
     * When `pwa.enabled` is on and `php bin/frasm pwa:build` has generated the manifests, it also links
     * the manifest and icons of the app whose scope contains the current page (the main app, or one
     * of `pwa.apps` such as /smarthome or an app bound to the current subdomain) and registers the
     * service worker (feature 'pwa').
     *
     * @param list<string>|null $features Client modules: 'nav', 'live', 'stream', 'push', 'pwa'
     *                                     (null = nav + live + stream, plus push and pwa when enabled).
     * @return string HTML markup.
     * @throws \Core\Exceptions\CoreException On an unknown feature name.
     */
    function frasm_head(?array $features = null): string
    {
        $container = \Core\Container\Container::getInstance();
        $request = $container->bound(\Core\Http\Request::class)
            ? $container->get(\Core\Http\Request::class)
            : \Core\Http\Request::fromGlobals();

        $pushEnabled = \Core\Push\PushManager::isEnabled();
        $pwaApp = \Core\Pwa\PwaBuilder::forPath($request->path(), $request->host());
        $pwaEnabled = $pwaApp !== null;

        if ($features === null) {
            $features = ['nav', 'live', 'stream'];
            if ($pushEnabled) {
                $features[] = 'push';
            }
            if ($pwaEnabled) {
                $features[] = 'pwa';
            }
        }

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $base = $request->basePath();

        $html = '<meta name="frasm-base" content="' . $escape($base) . '">' . "\n"
            . '<meta name="csrf-token" content="' . $escape(\Core\Security\Csrf::token()) . '">' . "\n";

        $serviceWorker = (string)\Core\Config\Config::get('push.service_worker', '/frasm-sw.js');
        // Every installable app registers the service worker with its own scope, so its push
        // subscription (and its notifications) belong to that app on Android
        $serviceWorkerScope = $base . ($pwaApp['scope'] ?? '/');

        if ($pushEnabled && in_array('push', $features, true)) {
            $html .= '<meta name="frasm-push-key" content="' . $escape(\Core\Push\PushManager::publicKey()) . '">' . "\n"
                . '<meta name="frasm-push-sw" content="' . $escape($serviceWorker) . '">' . "\n"
                . '<meta name="frasm-sw-scope" content="' . $escape($serviceWorkerScope) . '">' . "\n"
                . '<meta name="frasm-push-app" content="' . $escape($pwaApp['app'] ?? '') . '">' . "\n";
        }

        if ($pwaEnabled && in_array('pwa', $features, true)) {
            $html .= '<link rel="manifest" href="' . $escape($base . $pwaApp['manifest']) . '">' . "\n"
                . '<link rel="apple-touch-icon" href="' . $escape($base . $pwaApp['apple_icon']) . '">' . "\n"
                . '<meta name="theme-color" content="' . $escape($pwaApp['theme_color']) . '">' . "\n"
                . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
                . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
                . '<meta name="apple-mobile-web-app-title" content="' . $escape($pwaApp['title']) . '">' . "\n"
                . '<meta name="frasm-sw" content="' . $escape($serviceWorker) . '">' . "\n"
                . ($pushEnabled && in_array('push', $features, true) ? '' : '<meta name="frasm-sw-scope" content="' . $escape($serviceWorkerScope) . '">' . "\n");
        }

        foreach ($features as $feature) {
            if (!in_array($feature, ['nav', 'live', 'stream', 'push', 'pwa'], true)) {
                throw new \Core\Exceptions\CoreException("Unknown frasm_head() feature '{$feature}'.");
            }

            $file = FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . "frasm-{$feature}.js";
            $version = is_file($file) ? (string)filemtime($file) : '0';
            $html .= '<script src="' . $escape("{$base}/js/frasm-{$feature}.js?v={$version}") . '" defer data-frasm-track></script>' . "\n";
        }

        return $html;
    }
}
