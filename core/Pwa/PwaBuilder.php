<?php

declare(strict_types=1);

namespace Core\Pwa;

use Core\Config\Config;
use Core\Exceptions\CoreException;
use GdImage;

/**
 * @file PwaBuilder.php
 * @brief Generates the web app manifest and icons that make the site installable.
 */

/**
 * @class PwaBuilder
 * @brief Writes public/manifest.webmanifest and public/icons/* from config/pwa.php.
 *
 * The files are static, so the web server delivers them without running PHP. Icons are resized
 * with GD from `pwa.icon` (center-cropped to a square); without a source a placeholder in the theme
 * color is drawn. The maskable icon keeps the image inside the 80 % safe zone on the background color.
 */
class PwaBuilder
{
    /**
     * @var array<string, array{size: int, maskable?: bool}> Icon files (relative to public/icons) and their sizes.
     */
    public const ICONS = [
        'icon-192.png'          => ['size' => 192],
        'icon-512.png'          => ['size' => 512],
        'icon-maskable-512.png' => ['size' => 512, 'maskable' => true],
        'apple-touch-icon.png'  => ['size' => 180],
    ];

    /**
     * @var list<string> Allowed display modes.
     */
    private const DISPLAY_MODES = ['standalone', 'fullscreen', 'minimal-ui', 'browser'];

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
     * @brief Returns the public directory of the project.
     *
     * @return string
     */
    public function publicDir(): string
    {
        return FRASM_ROOT_DIR . DIRECTORY_SEPARATOR . 'public';
    }

    /**
     * @brief Returns the manifest path.
     *
     * @return string
     */
    public function manifestPath(): string
    {
        return $this->publicDir() . DIRECTORY_SEPARATOR . 'manifest.webmanifest';
    }

    /**
     * @brief Checks whether all generated files exist.
     *
     * @return bool
     */
    public function isBuilt(): bool
    {
        if (!is_file($this->manifestPath())) {
            return false;
        }

        foreach (array_keys(self::ICONS) as $file) {
            if (!is_file($this->publicDir() . '/icons/' . $file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @brief Builds the manifest data from the configuration.
     *
     * @return array<string, mixed>
     * @throws CoreException On invalid configuration values.
     */
    public function manifest(): array
    {
        $name = (string)(Config::get('pwa.name') ?? Config::get('app.name', 'Frasm'));
        $shortName = (string)(Config::get('pwa.short_name') ?? $name);
        $display = (string)Config::get('pwa.display', 'standalone');
        $startUrl = (string)Config::get('pwa.start_url', '/');

        if (!in_array($display, self::DISPLAY_MODES, true)) {
            throw new CoreException("Invalid pwa.display '{$display}' (allowed: " . implode(', ', self::DISPLAY_MODES) . ').');
        }
        if (!str_starts_with($startUrl, '/') || str_starts_with($startUrl, '//')) {
            throw new CoreException('pwa.start_url must be a path starting with "/".');
        }

        $icons = [];
        foreach (self::ICONS as $file => $icon) {
            if ($file === 'apple-touch-icon.png') {
                continue;
            }
            $icons[] = [
                'src'     => '/icons/' . $file,
                'sizes'   => "{$icon['size']}x{$icon['size']}",
                'type'    => 'image/png',
                'purpose' => empty($icon['maskable']) ? 'any' : 'maskable',
            ];
        }

        return array_filter([
            'id'               => $startUrl,
            'name'             => $name,
            'short_name'       => $shortName,
            'description'      => (string)Config::get('pwa.description', ''),
            'lang'             => (string)(Config::get('pwa.lang') ?? Config::get('app.locale', 'en')),
            'start_url'        => $startUrl,
            'scope'            => '/',
            'display'          => $display,
            'theme_color'      => $this->color('pwa.theme_color', '#343a40'),
            'background_color' => $this->color('pwa.background_color', '#ffffff'),
            'icons'            => $icons,
        ], fn(mixed $value): bool => $value !== '');
    }

    /**
     * @brief Writes the manifest and the icons.
     *
     * @param bool $force Regenerate icons even when they already exist.
     * @return list<string> Written files, relative to public/.
     * @throws CoreException When GD is missing, the source image is unusable or files cannot be written.
     */
    public function build(bool $force = false): array
    {
        // Validate the configuration before touching any file
        $manifest = json_encode($this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $written = [];

        $iconDir = $this->publicDir() . DIRECTORY_SEPARATOR . 'icons';
        $missing = array_filter(array_keys(self::ICONS), fn(string $file): bool => $force || !is_file("{$iconDir}/{$file}"));

        if ($missing !== []) {
            if (!extension_loaded('gd')) {
                throw new CoreException('Generating icons needs the PHP GD extension (e.g. `sudo apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-gd`), or run the command with a PHP binary that has it.');
            }
            if (!is_dir($iconDir) && !@mkdir($iconDir, 0755, true) && !is_dir($iconDir)) {
                throw new CoreException("Cannot create {$iconDir}.");
            }

            $source = $this->loadSource();
            $background = $this->color('pwa.background_color', '#ffffff');

            foreach ($missing as $file) {
                $icon = self::ICONS[$file];
                $image = empty($icon['maskable'])
                    ? $this->resize($source, $icon['size'], null, 1.0)
                    : $this->resize($source, $icon['size'], $background, 0.8);

                ob_start();
                imagepng($image, null, 9);
                $this->writeFile("{$iconDir}/{$file}", (string)ob_get_clean());
                $written[] = "icons/{$file}";
            }
        }

        // Written last: an existing manifest guarantees complete icons (frasm_head() links it only then)
        $this->writeFile($this->manifestPath(), $manifest);
        $written[] = 'manifest.webmanifest';

        return $written;
    }

    /**
     * @brief Loads and square-crops the configured source image, or draws a placeholder.
     *
     * @return GdImage Square image.
     * @throws CoreException If the source cannot be read.
     */
    private function loadSource(): GdImage
    {
        $configured = Config::get('pwa.icon');

        if ($configured === null || $configured === '') {
            return $this->placeholder(512);
        }

        $path = str_starts_with((string)$configured, '/') ? (string)$configured : FRASM_ROOT_DIR . '/' . $configured;
        $data = is_file($path) ? file_get_contents($path) : false;
        $image = $data === false ? false : @imagecreatefromstring($data);

        if ($image === false) {
            throw new CoreException("pwa.icon '{$configured}' is not a readable PNG, JPEG, GIF or WebP image.");
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
     * @brief Draws a placeholder icon: a white ring on the theme color.
     *
     * The ring is drawn as two filled ellipses at 4x resolution and downsampled, which smooths
     * the edges (GD does not antialias thick arcs).
     *
     * @param int $size Edge length in pixels.
     * @return GdImage
     */
    private function placeholder(int $size): GdImage
    {
        $large = $size * 4;
        $canvas = imagecreatetruecolor($large, $large);
        [$r, $g, $b] = $this->rgb($this->color('pwa.theme_color', '#343a40'));
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
     * @brief Reads and validates a #rrggbb color from the configuration.
     *
     * @param string $key Configuration key.
     * @param string $default Fallback.
     * @return string
     * @throws CoreException On an invalid color.
     */
    private function color(string $key, string $default): string
    {
        $value = (string)Config::get($key, $default);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $value)) {
            throw new CoreException("{$key} must be a color in the #rrggbb format.");
        }

        return strtolower($value);
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
