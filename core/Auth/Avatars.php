<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config\Config;
use Core\DB\DB;
use InvalidArgumentException;

/**
 * @file Avatars.php
 * @brief Profile photos of the accounts (frasm_users.avatar).
 */

/**
 * @class Avatars
 * @brief Stores profile photos as square JPEGs and keeps their version in frasm_users.avatar.
 *
 * Photos live in `auth.avatars` (default storage/app/avatars/<user ID>.jpg) and are served to signed-in users by
 * GET /_frasm/avatars/{id}?v=<version> (AvatarController; the version makes the address cacheable for good). An SSO
 * server passes the version in the identity and the photo through its API; SsoClient keeps a local copy, so a
 * client application never links the server. square() turns any photo into the stored form – applications use it
 * for photos of their own (e.g. a photo for one application only). Without a photo a user shows initials() in a
 * circle of color() – one color of COLORS per user, the same in every view and editor.
 */
class Avatars
{
    /**
     * @var int Side of a stored photo in pixels.
     */
    public const SIZE = 256;

    /**
     * @var int Largest accepted upload in bytes.
     */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @var list<string> Colors of the users (circles with initials, cursors of co-editing); readable with white text.
     */
    public const COLORS = ['#e11d48', '#2563eb', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#9333ea'];

    /**
     * @brief Color of a user: one of COLORS by the ID (users next to each other get different ones).
     *
     * @param int|string $userId User ID (a string – e.g. of an anonymous visitor – is hashed).
     * @return string #rrggbb
     */
    public static function color(int|string $userId): string
    {
        $index = is_int($userId) || ctype_digit($userId) ? (int)$userId : (int)hexdec(substr(sha1($userId), 0, 6));

        return self::COLORS[abs($index) % count(self::COLORS)];
    }

    /**
     * @brief Two letters of a name: of the first and the last word ("Jan Novák" → "JN"), of one word its first two.
     *
     * @param string $name Name.
     * @return string Upper case; "?" for an empty name.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s.]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '?';
        }
        $letters = count($words) > 1 ? mb_substr($words[0], 0, 1) . mb_substr((string)end($words), 0, 1) : mb_substr($words[0], 0, 2);

        return mb_strtoupper($letters);
    }

    /**
     * @brief A small copy of a photo as a data: URI (configurations of editors that cannot load it from this server).
     *
     * @param string $file Photo (a stored one: JPEG).
     * @param int $size Side in pixels.
     * @return string|null Null when the file is missing or unreadable.
     */
    public static function dataUri(string $file, int $size = 64): ?string
    {
        $image = @imagecreatefromjpeg($file);
        if ($image === false) {
            return null;
        }
        $small = imagescale($image, $size, $size) ?: $image;
        ob_start();
        imagejpeg($small, null, 82);

        return 'data:image/jpeg;base64,' . base64_encode((string)ob_get_clean());
    }

    /**
     * @brief Directory of the photos.
     *
     * @return string
     */
    public static function directory(): string
    {
        return rtrim((string)Config::get('auth.avatars', FRASM_STORAGE_DIR . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'avatars'), '/\\');
    }

    /**
     * @brief Returns the file of a user's photo.
     *
     * @param int $userId Local user ID.
     * @return string|null Null when the user has none.
     */
    public static function path(int $userId): ?string
    {
        $path = self::directory() . DIRECTORY_SEPARATOR . $userId . '.jpg';

        return is_file($path) ? $path : null;
    }

    /**
     * @brief Returns the version of a user's photo (part of its address).
     *
     * @param int $userId Local user ID.
     * @return string|null Null when the user has none.
     */
    public static function version(int $userId): ?string
    {
        $version = DB::getInstance()->selectValue('SELECT `avatar` FROM `frasm_users` WHERE `id` = ?', [$userId]);

        return is_string($version) && $version !== '' && self::path($userId) !== null ? $version : null;
    }

    /**
     * @brief Stores a photo uploaded by the user (or received from an SSO server).
     *
     * @param int $userId Local user ID.
     * @param string $image Content of the image (JPEG, PNG, WebP or GIF).
     * @param string|null $version Version to record (an SSO client keeps the server's); null = from the content.
     * @return string Version of the stored photo.
     * @throws InvalidArgumentException When the content is no image.
     */
    public static function store(int $userId, string $image, ?string $version = null): string
    {
        $jpeg = self::square($image);
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 02775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException("The directory of the photos cannot be created ({$directory}).");
        }
        $target = $directory . DIRECTORY_SEPARATOR . $userId . '.jpg';
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $jpeg) === false || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new InvalidArgumentException('The photo cannot be saved.');
        }
        @chmod($target, 0664);
        $version = $version !== null && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $version) ? $version : substr(sha1($jpeg), 0, 16);
        DB::getInstance()->query('UPDATE `frasm_users` SET `avatar` = ? WHERE `id` = ?', [$version, $userId]);

        return $version;
    }

    /**
     * @brief Removes a user's photo.
     *
     * @param int $userId Local user ID.
     * @return void
     */
    public static function remove(int $userId): void
    {
        @unlink(self::directory() . DIRECTORY_SEPARATOR . $userId . '.jpg');
        DB::getInstance()->query('UPDATE `frasm_users` SET `avatar` = NULL WHERE `id` = ?', [$userId]);
    }

    /**
     * @brief Turns a photo into the stored form: the middle square, SIZE × SIZE, upright (EXIF), JPEG without metadata.
     *
     * @param string $image Content of the image.
     * @return string JPEG.
     * @throws InvalidArgumentException When the content is no image or too large.
     */
    public static function square(string $image): string
    {
        if ($image === '' || strlen($image) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The photo is empty or too large.');
        }
        $info = @getimagesizefromstring($image);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) || $info[0] * $info[1] > 50_000_000) {
            throw new InvalidArgumentException('The photo must be a JPEG, PNG, WebP or GIF image.');
        }
        $source = @imagecreatefromstring($image);
        if ($source === false) {
            throw new InvalidArgumentException('The photo cannot be read.');
        }
        $source = self::upright($source, $image, $info[2]);

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($target, 0, 0, (int)imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($target, null, 86);

        return (string)ob_get_clean();
    }

    /**
     * @brief Turns a JPEG photo upright by its EXIF orientation (phones store them rotated).
     *
     * @param \GdImage $image Decoded image.
     * @param string $content Content of the file.
     * @param int $type IMAGETYPE_* constant.
     * @return \GdImage
     */
    private static function upright(\GdImage $image, string $content, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($content));
        $angle = match ((int)($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
