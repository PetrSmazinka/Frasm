<?php

declare(strict_types=1);

/**
 * Progressive Web App (installable web app)
 *
 * After changing these settings run `php bin/frasm pwa:build --force` to regenerate
 * public/manifest.webmanifest and the icons in public/icons/.
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

    // Source image for the icons, relative to the project root (square PNG/JPEG, at least 512×512 px).
    // null = a placeholder in the theme color.
    'icon' => null,
];
