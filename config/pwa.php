<?php

declare(strict_types=1);

/**
 * Progressive Web App (installable web app)
 *
 * After changing these settings run `php bin/frasm pwa:build --force` to regenerate
 * public/manifest.webmanifest and the icons in public/pwa/ (and public/pwa/<app>/ for `apps`).
 */
return [
    'enabled' => false,

    // Name shown by the operating system (null = app.name) and a short variant for the home screen (~12 characters)
    'name'       => null,
    'short_name' => null,
    'description' => '',

    // Language of the texts above (null = app.locale)
    'lang' => null,

    // Page opened by the installed app, and how it is displayed: standalone, fullscreen, minimal-ui or browser
    'start_url' => '/',
    'display'   => 'standalone',

    // Colors of the title bar and the splash screen (#rrggbb)
    'theme_color'      => '#343a40',
    'background_color' => '#ffffff',

    // Source image for the icons, relative to the project root: a square PNG/JPEG (at least 512×512 px)
    // or an SVG (needs rsvg-convert from librsvg2-bin). null = a placeholder in the theme color.
    'icon' => null,

    // Further installable apps for parts of the site, each with its own scope, name, colors and icon.
    // Pages within a scope offer that app instead of the main one (the longest scope wins). Missing
    // lang, display and colors are taken over from the main app. Example:
    //   'smarthome' => ['name' => 'SmartHome', 'scope' => '/smarthome', 'theme_color' => '#0b0e14',
    //                   'background_color' => '#0b0e14', 'icon' => 'app/Assets/img/smarthome.svg'],
    // (start_url defaults to the scope; write the scope without a trailing slash, so it covers /smarthome itself)
    'apps' => [],
];
