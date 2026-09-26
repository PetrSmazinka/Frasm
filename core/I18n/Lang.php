<?php

declare(strict_types=1);

namespace Core\I18n;

use Core\Config\Config;
use Core\Session\Session;

/**
 * @file Lang.php
 * @brief Localization service loading PHP dictionary files from app/Lang/<locale>/<file>.php.
 */

/**
 * @class Lang
 * @brief Resolves translation keys in the form "file.key" (nested keys: "file.group.key").
 *
 * Locale and file names are validated before they are used to build an include path,
 * so a user-controlled locale (e.g. from a query parameter) cannot cause file inclusion.
 */
class Lang
{
    /**
     * @var string Allowed locale format (e.g. 'cs', 'en', 'en_US', 'pt-BR').
     */
    protected const LOCALE_PATTERN = '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})?$/D';

    /**
     * @var string Allowed dictionary file name format.
     */
    protected const FILE_PATTERN = '/^[A-Za-z0-9_-]+$/D';

    protected static string $locale = 'cs';
    protected static string $fallbackLocale = 'en';

    /**
     * @var array<string, array<string, mixed>> Cache of loaded dictionaries keyed by "locale.file".
     */
    protected static array $loaded = [];

    /**
     * @brief Initializes locale from session (or configuration default) and the fallback locale.
     *
     * @return void
     */
    public static function boot(): void
    {
        $fallback = (string)Config::get('app.fallback_locale', 'en');
        self::$fallbackLocale = self::isValidLocale($fallback) ? $fallback : 'en';

        $default = (string)Config::get('app.locale', 'cs');
        $sessionLocale = Session::get('_locale');

        if (is_string($sessionLocale) && self::isValidLocale($sessionLocale)) {
            self::$locale = $sessionLocale;
        } else {
            self::$locale = self::isValidLocale($default) ? $default : self::$fallbackLocale;
        }
    }

    /**
     * @brief Sets the active locale and persists it in the session.
     *
     * @param string $locale Locale identifier (e.g. 'cs', 'en_US').
     * @return bool False when the locale identifier is malformed (the locale is left unchanged).
     */
    public static function setLocale(string $locale): bool
    {
        if (!self::isValidLocale($locale)) {
            return false;
        }

        self::$locale = $locale;
        Session::set('_locale', $locale);

        return true;
    }

    /**
     * @brief Returns the active locale.
     *
     * @return string
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * @brief Checks whether a locale identifier has a safe, well-formed format.
     *
     * @param string $locale Locale identifier.
     * @return bool
     */
    public static function isValidLocale(string $locale): bool
    {
        return preg_match(self::LOCALE_PATTERN, $locale) === 1;
    }

    /**
     * @brief Translates a key in the form "file.key" (e.g. "messages.welcome").
     *
     * @param string $key Translation key.
     * @param array<string, scalar> $replace Placeholder values (e.g. ['name' => 'Karel'] replaces ':name').
     * @param string|null $locale Locale override.
     * @return string Translated line, or the key itself when no translation exists.
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $targetLocale = ($locale !== null && self::isValidLocale($locale)) ? $locale : self::$locale;

        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return $key;
        }

        [$file, $item] = $parts;

        $line = self::loadLine($targetLocale, $file, $item);

        // Fallback to the secondary locale when the key is missing
        if ($line === null && $targetLocale !== self::$fallbackLocale) {
            $line = self::loadLine(self::$fallbackLocale, $file, $item);
        }

        if ($line === null) {
            return $key;
        }

        if ($replace !== []) {
            // Longest placeholders first so ':name' does not break ':name_full'
            uksort($replace, fn($a, $b): int => strlen((string)$b) <=> strlen((string)$a));
            $pairs = [];
            foreach ($replace as $placeholder => $value) {
                $pairs[':' . $placeholder] = (string)$value;
            }
            $line = strtr($line, $pairs);
        }

        return $line;
    }

    /**
     * @brief Loads a single line from a dictionary file.
     *
     * Flat keys ('min.string' => '...') take precedence over nested arrays ('min' => ['string' => '...']).
     *
     * @param string $locale Validated locale identifier.
     * @param string $file Dictionary file name.
     * @param string $item Key within the file (dot notation for nested arrays).
     * @return string|null
     */
    protected static function loadLine(string $locale, string $file, string $item): ?string
    {
        if (!self::isValidLocale($locale) || preg_match(self::FILE_PATTERN, $file) !== 1) {
            return null;
        }

        $cacheKey = "{$locale}.{$file}";

        if (!isset(self::$loaded[$cacheKey])) {
            $langDir = defined('FRASM_APP_DIR')
                ? FRASM_APP_DIR . DIRECTORY_SEPARATOR . 'Lang'
                : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Lang';
            $path = $langDir . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $file . '.php';

            $content = is_file($path) ? require $path : [];
            self::$loaded[$cacheKey] = is_array($content) ? $content : [];
        }

        $dictionary = self::$loaded[$cacheKey];

        if (array_key_exists($item, $dictionary)) {
            return is_string($dictionary[$item]) ? $dictionary[$item] : null;
        }

        $current = $dictionary;
        foreach (explode('.', $item) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_string($current) ? $current : null;
    }
}
