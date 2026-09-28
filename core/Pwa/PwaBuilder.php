<?php

declare(strict_types=1);

namespace Core\Pwa;

use Core\Config\Config;
use Core\Exceptions\CoreException;
use GdImage;

/**
 * @file PwaBuilder.php
 * @brief Generates the web app manifests and icons that make the site (or parts of it) installable.
 */

/**
 * @class PwaBuilder
 * @brief Builds one or more installable web apps from config/pwa.php.
 *
 * The main app (top-level keys, scope "/") writes public/manifest.webmanifest and public/pwa/*.
 * Every entry of `pwa.apps` is a further app with its own scope (for example "/smarthome"), name,
 * colors and icon; it writes public/pwa/<name>/manifest.webmanifest and its icons next to it.
 * Missing colors, language and display mode are taken over from the main app.
 *
 * frasm_head() links the manifest of the app whose scope contains the current page (the longest
 * matching scope wins), so each part of the site offers its own app. All apps use the same service
 * worker script, each registered with the app's scope: push subscriptions made in an app belong to
 * it, and Android shows their notifications as notifications of that installed app.
 *
 * Android (Chrome) does not install two apps whose scopes are nested ("/" and "/smarthome") side by
 * side; install one of them per device, or serve independent apps from separate (sub)domains.
 *
 * Icons are made from a PNG, JPEG, GIF or WebP with GD, or from an SVG with rsvg-convert (librsvg).
 */
class PwaBuilder
{
    /**
     * @var string Icon directory below public/. Not "icons": Debian/Ubuntu Apache maps /icons/ globally
     *             to /usr/share/apache2/icons/ (mod_alias), which would make the icons unreachable.
     */
    public const ICON_DIR = 'pwa';

    /**
     * @var array<string, array{size: int, maskable?: bool}> Icon files (next to the manifest's icons) and their sizes.
     */
    public const ICONS = [
        'icon-192.png'          => ['size' => 192],
        'icon-512.png'          => ['size' => 512],
        'icon-maskable-512.png' => ['size' => 512, 'maskable' => true],
        'apple-touch-icon.png'  => ['size' => 180],
    ];

    /**
     * @var string Main app identifier (the top-level keys of config/pwa.php).
     */
    public const MAIN = '';

    /**
     * @var list<string> Allowed display modes.
     */
    private const DISPLAY_MODES = ['standalone', 'fullscreen', 'minimal-ui', 'browser'];

    /**
     * @var string Allowed names of additional apps (also their directory below public/pwa).
     */
    private const APP_NAME = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

    /**
     * @var int Edge length an SVG icon is rasterized to before resizing.
     */
    private const SVG_SIZE = 1024;

    /**
     * @brief Checks whether PWA support is enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool)Config::get('pwa.enabled', false);
    }

    /**
     * @brief Finds the built app whose scope contains a path (longest scope wins).
     *
     * @param string $path Request path relative to the base path (Request::path()).
     * @return array{app: string, scope: string, manifest: string, apple_icon: string, theme_color: string, title: string}|null
     *         URLs relative to the base path, or null when PWA is off or no app covers the path.
     */
    public static function forPath(string $path): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }

        $builder = new self();
        $best = null;
        $bestLength = -1;
        foreach ($builder->appNames() as $app) {
            $scope = $app === self::MAIN ? '/' : (string)(Config::get("pwa.apps.{$app}.scope") ?? Config::get("pwa.apps.{$app}.start_url") ?? '');
            if (!self::inScope($path, $scope) || strlen($scope) <= $bestLength || !is_file($builder->manifestPath($app))) {
                continue;
            }
            $best = $app;
            $bestLength = strlen($scope);
        }

        if ($best === null) {
            return null;
        }

        $settings = $builder->settings($best);
        $dir = $builder->urlDir($best);

        return [
            'app'         => $best,
            'scope'       => $settings['scope'],
            'manifest'    => $best === self::MAIN ? '/manifest.webmanifest' : "{$dir}/manifest.webmanifest",
            'apple_icon'  => "{$dir}/apple-touch-icon.png",
            'theme_color' => $settings['theme_color'],
            'title'       => $settings['short_name'],
        ];
    }

    /**
     * @brief Returns the configured apps: the main app ('') and the names of `pwa.apps`.
     *
     * @return list<string>
     * @throws CoreException On an invalid app name.
     */
    public function appNames(): array
    {
        $apps = [self::MAIN];
        foreach (array_keys((array)Config::get('pwa.apps', [])) as $name) {
            if (!is_string($name) || !preg_match(self::APP_NAME, $name)) {
                throw new CoreException("Invalid PWA app name '{$name}' (lowercase letters, digits and dashes).");
            }
            $apps[] = $name;
        }

        return $apps;
    }

    /**
     * @brief Returns the public directory of the project.
     *
     * @return string
     */
    public function publicDir(): string
    {
        return FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'public';
    }

    /**
     * @brief Returns the manifest path of an app.
     *
     * @param string $app App name (MAIN for the main app).
     * @return string
     */
    public function manifestPath(string $app = self::MAIN): string
    {
        return $app === self::MAIN
            ? $this->publicDir() . DIRECTORY_SEPARATOR . 'manifest.webmanifest'
            : $this->iconDir($app) . DIRECTORY_SEPARATOR . 'manifest.webmanifest';
    }

    /**
     * @brief Returns the icon directory of an app.
     *
     * @param string $app App name.
     * @return string
     */
    public function iconDir(string $app = self::MAIN): string
    {
        return $this->publicDir() . DIRECTORY_SEPARATOR . self::ICON_DIR . ($app === self::MAIN ? '' : DIRECTORY_SEPARATOR . $app);
    }

    /**
     * @brief Checks whether all generated files of an app exist.
     *
     * @param string $app App name.
     * @return bool
     */
    public function isBuilt(string $app = self::MAIN): bool
    {
        if (!is_file($this->manifestPath($app))) {
            return false;
        }

        foreach (array_keys(self::ICONS) as $file) {
            if (!is_file($this->iconDir($app) . DIRECTORY_SEPARATOR . $file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @brief Returns the validated settings of an app (additional apps inherit from the main app).
     *
     * @param string $app App name.
     * @return array{name: string, short_name: string, description: string, lang: string, start_url: string, scope: string, display: string, theme_color: string, background_color: string, icon: string|null}
     * @throws CoreException On invalid configuration values.
     */
    public function settings(string $app = self::MAIN): array
    {
        $main = [
            'name'             => (string)(Config::get('pwa.name') ?? Config::get('app.name', 'Frasm')),
            'description'      => (string)Config::get('pwa.description', ''),
            'lang'             => (string)(Config::get('pwa.lang') ?? Config::get('app.locale', 'en')),
            'start_url'        => (string)Config::get('pwa.start_url', '/'),
            'scope'            => '/',
            'display'          => (string)Config::get('pwa.display', 'standalone'),
            'theme_color'      => (string)Config::get('pwa.theme_color', '#343a40'),
            'background_color' => (string)Config::get('pwa.background_color', '#ffffff'),
            'icon'             => Config::get('pwa.icon'),
        ];
        $main['short_name'] = (string)(Config::get('pwa.short_name') ?? $main['name']);

        if ($app === self::MAIN) {
            $settings = $main;
            $key = 'pwa';
        } else {
            $own = Config::get("pwa.apps.{$app}");
            if (!is_array($own)) {
                throw new CoreException("Unknown PWA app '{$app}'.");
            }
            $key = "pwa.apps.{$app}";
            if (!isset($own['name']) || trim((string)$own['name']) === '') {
                throw new CoreException("{$key}.name is required.");
            }
            $scope = (string)($own['scope'] ?? $own['start_url'] ?? '');
            $settings = [
                'name'             => (string)$own['name'],
                'short_name'       => (string)($own['short_name'] ?? $own['name']),
                'description'      => (string)($own['description'] ?? ''),
                'lang'             => (string)($own['lang'] ?? $main['lang']),
                'start_url'        => (string)($own['start_url'] ?? $scope),
                'scope'            => $scope,
                'display'          => (string)($own['display'] ?? $main['display']),
                'theme_color'      => (string)($own['theme_color'] ?? $main['theme_color']),
                'background_color' => (string)($own['background_color'] ?? $main['background_color']),
                'icon'             => $own['icon'] ?? null,
            ];
        }

        if (!in_array($settings['display'], self::DISPLAY_MODES, true)) {
            throw new CoreException("Invalid {$key}.display '{$settings['display']}' (allowed: " . implode(', ', self::DISPLAY_MODES) . ').');
        }
        foreach (['start_url', 'scope'] as $field) {
            if (!str_starts_with($settings[$field], '/') || str_starts_with($settings[$field], '//')) {
                throw new CoreException("{$key}.{$field} must be a path starting with \"/\".");
            }
        }
        if (!self::inScope((string)parse_url($settings['start_url'], PHP_URL_PATH), $settings['scope'])) {
            throw new CoreException("{$key}.start_url must lie within its scope '{$settings['scope']}'.");
        }
        foreach (['theme_color', 'background_color'] as $field) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $settings[$field])) {
                throw new CoreException("{$key}.{$field} must be a color in the #rrggbb format.");
            }
            $settings[$field] = strtolower($settings[$field]);
        }
        $settings['icon'] = $settings['icon'] === null || $settings['icon'] === '' ? null : (string)$settings['icon'];

        return $settings;
    }

    /**
     * @brief Builds the manifest data of an app.
     *
     * @param string $app App name.
     * @return array<string, mixed>
     * @throws CoreException On invalid configuration values.
     */
    public function manifest(string $app = self::MAIN): array
    {
        $settings = $this->settings($app);

        $icons = [];
        foreach (self::ICONS as $file => $icon) {
            if ($file === 'apple-touch-icon.png') {
                continue;
            }
            $icons[] = [
                // Relative to the manifest, so the app also works below a base path
                'src'     => $app === self::MAIN ? self::ICON_DIR . '/' . $file : $file,
                'sizes'   => "{$icon['size']}x{$icon['size']}",
                'type'    => 'image/png',
                'purpose' => empty($icon['maskable']) ? 'any' : 'maskable',
            ];
        }

        return array_filter([
            // The main app keeps its id (= start_url), so already installed apps stay the same app
            'id'               => $settings['start_url'],
            'name'             => $settings['name'],
            'short_name'       => $settings['short_name'],
            'description'      => $settings['description'],
            'lang'             => $settings['lang'],
            'start_url'        => $settings['start_url'],
            'scope'            => $settings['scope'],
            'display'          => $settings['display'],
            'theme_color'      => $settings['theme_color'],
            'background_color' => $settings['background_color'],
            'icons'            => $icons,
        ], fn(mixed $value): bool => $value !== '');
    }

    /**
     * @brief Writes the manifests and icons of all apps.
     *
     * @param bool $force Regenerate icons that already exist.
     * @return list<string> Written files, relative to public/.
     * @throws CoreException When GD is missing, a source image is unusable or files cannot be written.
     */
    public function build(bool $force = false): array
    {
        // Validate every app before touching any file
        $manifests = [];
        foreach ($this->appNames() as $app) {
            $manifests[$app] = json_encode($this->manifest($app), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        $written = [];
        foreach ($manifests as $app => $manifest) {
            $written = [...$written, ...$this->buildApp($app, $manifest, $force)];
        }

        return $written;
    }

    /**
     * @brief Writes the icons and the manifest of one app.
     *
     * @param string $app App name.
     * @param string $manifest Encoded manifest.
     * @param bool $force Regenerate existing icons.
     * @return list<string> Written files, relative to public/.
     * @throws CoreException
     */
    private function buildApp(string $app, string $manifest, bool $force): array
    {
        $settings = $this->settings($app);
        $iconDir = $this->iconDir($app);
        $relative = self::ICON_DIR . ($app === self::MAIN ? '' : "/{$app}");
        $written = [];
        $missing = array_filter(array_keys(self::ICONS), fn(string $file): bool => $force || !is_file("{$iconDir}/{$file}"));

        if ($missing !== []) {
            if (!extension_loaded('gd')) {
                throw new CoreException('Generating icons needs the PHP GD extension (e.g. `sudo apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-gd`), or run the command with a PHP binary that has it.');
            }
            if (!is_dir($iconDir) && !@mkdir($iconDir, 0755, true) && !is_dir($iconDir)) {
                throw new CoreException("Cannot create {$iconDir}.");
            }

            $source = $this->loadSource($settings['icon'], $settings['theme_color']);
            foreach ($missing as $file) {
                $icon = self::ICONS[$file];
                $image = empty($icon['maskable'])
                    ? $this->resize($source, $icon['size'], null, 1.0)
                    : $this->resize($source, $icon['size'], $settings['background_color'], 0.8);

                ob_start();
                imagepng($image, null, 9);
                $this->writeFile("{$iconDir}/{$file}", (string)ob_get_clean());
                $written[] = "{$relative}/{$file}";
            }
        }

        // Written last: an existing manifest guarantees complete icons (frasm_head() links it only then)
        $this->writeFile($this->manifestPath($app), $manifest);
        $written[] = $app === self::MAIN ? 'manifest.webmanifest' : "{$relative}/manifest.webmanifest";

        return $written;
    }

    /**
     * @brief Tells whether a path lies within a scope ("/smarthome" covers "/smarthome" and "/smarthome/x",
     *        not "/smarthomes").
     *
     * @param string $path Path.
     * @param string $scope Scope path.
     * @return bool
     */
    private static function inScope(string $path, string $scope): bool
    {
        if ($scope === '') {
            return false;
        }
        $scope = rtrim($scope, '/');

        return $scope === '' || $path === $scope || str_starts_with($path, $scope . '/');
    }

    /**
     * @brief URL directory of an app's icons, relative to the base path.
     *
     * @param string $app App name.
     * @return string
     */
    private function urlDir(string $app): string
    {
        return '/' . self::ICON_DIR . ($app === self::MAIN ? '' : "/{$app}");
    }

    /**
     * @brief Loads and square-crops a source image, or draws a placeholder.
     *
     * @param string|null $configured Image path (relative to the project root) or null.
     * @param string $themeColor Color of the placeholder.
     * @return GdImage Square image.
     * @throws CoreException If the source cannot be read.
     */
    private function loadSource(?string $configured, string $themeColor): GdImage
    {
        if ($configured === null) {
            return $this->placeholder(512, $themeColor);
        }

        $path = str_starts_with($configured, '/') ? $configured : FRASM_ROOT_DIR . '/' . $configured;
        if (!is_file($path)) {
            throw new CoreException("PWA icon '{$configured}' does not exist.");
        }

        $data = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg' ? $this->rasterizeSvg($path) : file_get_contents($path);
        $image = $data === false ? false : @imagecreatefromstring($data);

        if ($image === false) {
            throw new CoreException("PWA icon '{$configured}' is not a readable PNG, JPEG, GIF, WebP or SVG image.");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $square = imagecreatetruecolor($side, $side);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopy($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $side, $side);

        return $square;
    }

    /**
     * @brief Renders an SVG to PNG with rsvg-convert (librsvg; no shell is involved).
     *
     * @param string $path SVG file.
     * @return string PNG data.
     * @throws CoreException When rsvg-convert is missing or fails.
     */
    private function rasterizeSvg(string $path): string
    {
        $binary = null;
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            if ($dir !== '' && is_executable("{$dir}/rsvg-convert")) {
                $binary = "{$dir}/rsvg-convert";
                break;
            }
        }
        if ($binary === null) {
            throw new CoreException('SVG icons need rsvg-convert (e.g. `sudo apt install librsvg2-bin`); or use a PNG icon.');
        }

        $size = (string)self::SVG_SIZE;
        $process = proc_open([$binary, '--width', $size, '--height', $size, '--keep-aspect-ratio', '--format', 'png', $path], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new CoreException('Cannot run rsvg-convert.');
        }

        $png = (string)stream_get_contents($pipes[1]);
        $error = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || $png === '') {
            throw new CoreException("rsvg-convert could not render {$path}" . ($error !== '' ? ": {$error}" : '.'));
        }

        return $png;
    }

    /**
     * @brief Draws a placeholder icon: a white ring on the theme color.
     *
     * The ring is drawn as two filled ellipses at 4x resolution and downsampled, which smooths
     * the edges (GD does not antialias thick arcs).
     *
     * @param int $size Edge length in pixels.
     * @param string $color Theme color (#rrggbb).
     * @return GdImage
     */
    private function placeholder(int $size, string $color): GdImage
    {
        $large = $size * 4;
        $canvas = imagecreatetruecolor($large, $large);
        [$r, $g, $b] = $this->rgb($color);
        $theme = imagecolorallocate($canvas, $r, $g, $b);
        imagefill($canvas, 0, 0, $theme);

        $center = intdiv($large, 2);
        imagefilledellipse($canvas, $center, $center, (int)($large * 0.46), (int)($large * 0.46), imagecolorallocate($canvas, 255, 255, 255));
        imagefilledellipse($canvas, $center, $center, (int)($large * 0.34), (int)($large * 0.34), $theme);

        $image = imagecreatetruecolor($size, $size);
        imagecopyresampled($image, $canvas, 0, 0, 0, 0, $size, $size, $large, $large);

        return $image;
    }

    /**
     * @brief Resizes a square image, optionally centered on a colored background.
     *
     * @param GdImage $source Square source.
     * @param int $size Target edge length.
     * @param string|null $background Background color (#rrggbb) or null for transparency.
     * @param float $scale Share of the canvas covered by the image (maskable icons use 0.8).
     * @return GdImage
     */
    private function resize(GdImage $source, int $size, ?string $background, float $scale): GdImage
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if ($background === null) {
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            [$r, $g, $b] = $this->rgb($background);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
        }

        imagealphablending($canvas, true);
        $inner = (int)round($size * $scale);
        $offset = intdiv($size - $inner, 2);
        imagecopyresampled($canvas, $source, $offset, $offset, 0, 0, $inner, $inner, imagesx($source), imagesy($source));

        return $canvas;
    }

    /**
     * @brief Converts #rrggbb to RGB components.
     *
     * @param string $hex Color.
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /**
     * @brief Writes a file atomically.
     *
     * @param string $path Target path.
     * @param string $content Content.
     * @return void
     * @throws CoreException When the file cannot be written.
     */
    private function writeFile(string $path, string $content): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $content) === false || !@rename($temp, $path)) {
            @unlink($temp);
            throw new CoreException("Cannot write {$path} (check permissions).");
        }
        @chmod($path, 0644);
    }
}
