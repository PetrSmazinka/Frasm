<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * @file Collation.php
 * @class Collation
 * @brief Czech alphabetical order of texts without the intl extension ("Údaje" before "Záznamník", "Chata" after
 *        "Hrad", "Čáp" after "Cukr", "foto 2" before "foto 10").
 *
 * Letters by the Czech alphabet: a b c č d e f g h ch i j k l m n o p q r ř s š t u v w x y z ž – č, ř, š, ž and ch are
 * letters of their own, the other accents (á, ď, é, ě, í, ň, ó, ť, ú, ů, ý and foreign ones like ä, ö, ü) count as the
 * plain letter and decide only between otherwise equal texts. Case decides last. Runs of digits compare as numbers.
 */
final class Collation
{
    /**
     * @var list<string> The alphabet in its order.
     */
    private const ALPHABET = ['a', 'b', 'c', 'č', 'd', 'e', 'f', 'g', 'h', 'ch', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r',
        'ř', 's', 'š', 't', 'u', 'v', 'w', 'x', 'y', 'z', 'ž'];

    /**
     * @var array<string, string> Accented letters counted as their plain letter.
     */
    private const PLAIN = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ę' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l', 'ń' => 'n', 'ň' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'õ' => 'o', 'ő' => 'o', 'ø' => 'o', 'ŕ' => 'r', 'ś' => 's', 'ß' => 's', 'ť' => 't', 'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ü' => 'u', 'ů' => 'u', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z',
    ];

    /**
     * @var array<string, string>|null Letter → its code in a key (bytes 0x61… keep digits, spaces and punctuation before
     *      letters and other scripts after them).
     */
    private static ?array $codes = null;

    /**
     * @brief Compares two texts in the Czech order (for usort()).
     *
     * @param string $a Text.
     * @param string $b Text.
     * @return int <0, 0 or >0.
     */
    public static function compare(string $a, string $b): int
    {
        return strnatcmp(self::key($a), self::key($b))
            ?: strnatcmp(mb_strtolower($a, 'UTF-8'), mb_strtolower($b, 'UTF-8'))
            ?: strcmp($b, $a);
    }

    /**
     * @brief Sorts texts in the Czech order.
     *
     * @param list<string> $texts Texts.
     * @return list<string>
     */
    public static function sort(array $texts): array
    {
        usort($texts, self::compare(...));

        return $texts;
    }

    /**
     * @brief The primary key of a text: its letters as codes in the order of the alphabet (for strnatcmp()).
     *
     * @param string $text Text.
     * @return string
     */
    public static function key(string $text): string
    {
        if (self::$codes === null) {
            self::$codes = [];
            foreach (self::ALPHABET as $index => $letter) {
                self::$codes[$letter] = chr(0x61 + $index);
            }
        }
        $chars = mb_str_split(mb_strtolower($text, 'UTF-8'), 1, 'UTF-8');
        $key = '';
        for ($i = 0, $count = count($chars); $i < $count; $i++) {
            $char = $chars[$i];
            if ($char === 'c' && ($chars[$i + 1] ?? '') === 'h') {
                $key .= self::$codes['ch'];
                $i++;
                continue;
            }
            $char = self::PLAIN[$char] ?? $char;
            // Punctuation among the codes of letters (` { | } ~) goes before them
            $key .= self::$codes[$char] ?? (strlen($char) === 1 && ord($char) >= 0x60 ? '_' : $char);
        }

        return $key;
    }
}
