<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * @file Phone.php
 * @class Phone
 * @brief Telephone numbers in the international form (E.164, "+420777123456").
 *
 * Forms enhance <input type="tel"> with frasm-phone.js (a country picker, the number sent in the international form);
 * the server normalizes what comes anyway – typed without JavaScript, imported, older data: spaces, dashes, dots,
 * slashes and parentheses go, "00" becomes "+", a national number (9 digits, the default country) gets its prefix.
 */
final class Phone
{
    /**
     * @var string Country calling code of national numbers (without "+"), app.phone_country_code overrides it.
     */
    private const DEFAULT_CODE = '420';

    /**
     * @brief The number in the international form.
     *
     * @param string $input Number as written ("777 123 456", "+420 777-123-456", "00421 905 123 456").
     * @param string|null $countryCode Calling code of national numbers (default app.phone_country_code or 420).
     * @return string|null "+420777123456"; "" for an empty input; null when it is not a telephone number.
     */
    public static function normalize(string $input, ?string $countryCode = null): ?string
    {
        $number = preg_replace('/[\s\-.\/()\x{00A0}]+/u', '', trim($input)) ?? '';
        if ($number === '') {
            return '';
        }
        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        }
        if (str_starts_with($number, '+')) {
            $digits = substr($number, 1);

            return preg_match('/^[1-9]\d{6,14}$/D', $digits) === 1 ? "+{$digits}" : null;
        }
        if (preg_match('/^\d{9}$/D', $number) !== 1) {
            return null;
        }
        $code = $countryCode ?? (string)(\Core\Config\Config::get('app.phone_country_code', self::DEFAULT_CODE) ?: self::DEFAULT_CODE);

        return '+' . ltrim($code, '+') . $number;
    }

    /**
     * @brief Whether a text is a telephone number (empty is not).
     *
     * @param string $input Number.
     * @return bool
     */
    public static function isValid(string $input): bool
    {
        $number = self::normalize($input);

        return $number !== null && $number !== '';
    }

    /**
     * @brief The number for reading: Czech and Slovak ones in threes ("+420 777 123 456"), others as they are.
     *
     * @param string $number Number (any form).
     * @return string
     */
    public static function format(string $number): string
    {
        $normalized = self::normalize($number);
        if ($normalized === null || $normalized === '') {
            return trim($number);
        }
        if (preg_match('/^\+(420|421)(\d{3})(\d{3})(\d{3})$/D', $normalized, $parts) === 1) {
            return "+{$parts[1]} {$parts[2]} {$parts[3]} {$parts[4]}";
        }

        return $normalized;
    }

    /**
     * @brief A link to call the number ("tel:+420777123456").
     *
     * @param string $number Number.
     * @return string
     */
    public static function href(string $number): string
    {
        return 'tel:' . (self::normalize($number) ?: preg_replace('/\s+/', '', $number));
    }
}
