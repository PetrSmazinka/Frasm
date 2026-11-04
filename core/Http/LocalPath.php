<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * @file LocalPath.php
 * @brief A path of this site taken from user input (where to go back, return after sign-in) – never another site.
 */

/**
 * @class LocalPath
 * @brief Accepts only a path of this site, so a redirect to a value from a request cannot lead elsewhere (open redirect).
 *
 * Browsers read `//host` and `/\host` as another site and drop control characters inside a URL, so a value is a local
 * path only when it starts with a single `/`, contains no backslash and no control character and is not absurdly long.
 * Query string and fragment are allowed. Use this (or BaseController::localPath()) for every redirect target that
 * comes from a request; never write the check by hand.
 */
final class LocalPath
{
    /**
     * @var int Longest accepted path.
     */
    public const MAX_LENGTH = 2000;

    /**
     * @brief Returns the value when it is a path of this site, otherwise null.
     *
     * @param mixed $value Requested path (a query/form value, a stored link, ...).
     * @return string|null The path, or null when it is not a local path.
     */
    public static function from(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH || $value[0] !== '/'
            || preg_match('#^//|[\\\\\x00-\x1F\x7F]#', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * @brief Returns the value when it is a path of this site, otherwise the fallback.
     *
     * @param mixed $value Requested path.
     * @param string $fallback Path used for anything else.
     * @return string
     */
    public static function orElse(mixed $value, string $fallback = '/'): string
    {
        return self::from($value) ?? $fallback;
    }
}
