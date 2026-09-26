<?php

use Core\I18n\Lang;

if (!function_exists('__')) {
    /**
     * Zkratka pro překlad textu.
     *
     * @param string $key Klíč ve formátu 'soubor.polozka'
     * @param array<string, scalar> $replace Pole zástupných parametrů
     */
    function __(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }
}