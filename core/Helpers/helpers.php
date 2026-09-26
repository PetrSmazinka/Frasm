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

if (!function_exists('frasm_head')) {
    /**
     * @brief Returns the framework <head> markup: base path, CSRF token, Web Push key and client scripts.
     *
     * Place it inside <head> of every layout. Scripts are versioned by modification time and marked
     * with data-frasm-track, so a deployment of new framework scripts forces a full page reload.
     *
     * @param list<string>|null $features Client modules: 'nav', 'live', 'stream', 'push'
     *                                     (null = nav + live + stream, plus push when enabled).
     * @return string HTML markup.
     * @throws \Core\Exceptions\CoreException On an unknown feature name.
     */
    function frasm_head(?array $features = null): string
    {
        $pushEnabled = \Core\Push\PushManager::isEnabled();
        $features ??= $pushEnabled ? ['nav', 'live', 'stream', 'push'] : ['nav', 'live', 'stream'];

        $container = \Core\Container\Container::getInstance();
        $request = $container->bound(\Core\Http\Request::class)
            ? $container->get(\Core\Http\Request::class)
            : \Core\Http\Request::fromGlobals();

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $base = $request->basePath();

        $html = '<meta name="frasm-base" content="' . $escape($base) . '">' . "\n"
            . '<meta name="csrf-token" content="' . $escape(\Core\Security\Csrf::token()) . '">' . "\n";

        if ($pushEnabled && in_array('push', $features, true)) {
            $html .= '<meta name="frasm-push-key" content="' . $escape(\Core\Push\PushManager::publicKey()) . '">' . "\n"
                . '<meta name="frasm-push-sw" content="' . $escape((string)\Core\Config\Config::get('push.service_worker', '/frasm-sw.js')) . '">' . "\n";
        }

        foreach ($features as $feature) {
            if (!in_array($feature, ['nav', 'live', 'stream', 'push'], true)) {
                throw new \Core\Exceptions\CoreException("Unknown frasm_head() feature '{$feature}'.");
            }

            $file = FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . "frasm-{$feature}.js";
            $version = is_file($file) ? (string)filemtime($file) : '0';
            $html .= '<script src="' . $escape("{$base}/js/frasm-{$feature}.js?v={$version}") . '" defer data-frasm-track></script>' . "\n";
        }

        return $html;
    }
}
