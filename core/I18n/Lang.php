<?php

declare(strict_types=1);

namespace Core\I18n;

use Core\Config\Config;
use Core\Session\Session;

class Lang
{
    protected static string $locale = 'cs';
    protected static string $fallbackLocale = 'en';

    /**
     * @var array<string, array<string, mixed>> Cache pro načtené slovníky
     */
    protected static array $loaded = [];

    public static function boot(): void
    {
        self::$fallbackLocale = (string)Config::get('app.fallback_locale', 'en');
        
        // Zjištění jazyka ze session, jinak výchozí z konfigurace
        $default = (string)Config::get('app.locale', 'cs');
        self::$locale = (string)Session::get('_locale', $default);
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
        Session::set('_locale', $locale);
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Přeloží klíč ve formátu "soubor.klic" (např. "messages.welcome")
     *
     * @param string $key Klíč zápisu
     * @param array<string, scalar> $replace Parametry k nahrazení (např. ['name' => 'Karel'])
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $targetLocale = $locale ?? self::$locale;

        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return $key;
        }

        [$file, $item] = $parts;

        $line = self::loadLine($targetLocale, $file, $item);

        // Fallback na záložní jazyk, pokud klíč neexistuje
        if ($line === null && $targetLocale !== self::$fallbackLocale) {
            $line = self::loadLine(self::$fallbackLocale, $file, $item);
        }

        if ($line === null) {
            return $key;
        }

        // Nahrazení parametrů (:name -> Karel)
        foreach ($replace as $placeholder => $value) {
            $line = str_replace(':' . $placeholder, (string)$value, $line);
        }

        return $line;
    }

    protected static function loadLine(string $locale, string $file, string $item): ?string
    {
        $cacheKey = "{$locale}.{$file}";

        if (!isset(self::$loaded[$cacheKey])) {
            $langDir = defined('FRASM_APP_DIR') ? FRASM_APP_DIR . '/Lang' : __DIR__ . '/../../../app/Lang';
            $path = "{$langDir}/{$locale}/{$file}.php";

            if (is_file($path)) {
                self::$loaded[$cacheKey] = require $path;
            } else {
                self::$loaded[$cacheKey] = [];
            }
        }

        return self::$loaded[$cacheKey][$item] ?? null;
    }
}