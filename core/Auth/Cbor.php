<?php

declare(strict_types=1);

namespace Core\Auth;

use InvalidArgumentException;

/**
 * @file Cbor.php
 * @brief Minimal CBOR (RFC 8949) decoder for WebAuthn attestation objects and COSE keys.
 */

/**
 * @class Cbor
 * @brief Decodes the subset of CBOR used by WebAuthn: unsigned and negative integers, byte and text strings, arrays,
 *        maps, booleans and null (definite lengths only; floats and tags are refused).
 */
final class Cbor
{
    /**
     * @var int Deepest nesting accepted.
     */
    private const MAX_DEPTH = 16;

    /**
     * @brief Decodes one item from the start of a buffer.
     *
     * @param string $data CBOR bytes.
     * @param int $offset Where to start; set to the end of the item.
     * @return mixed Integers, strings (bytes and text alike), arrays (lists and maps), bool or null.
     * @throws InvalidArgumentException On malformed or unsupported data.
     */
    public static function decode(string $data, int &$offset = 0): mixed
    {
        return self::item($data, $offset, 0);
    }

    /**
     * @brief Decodes an item.
     *
     * @param string $data Buffer.
     * @param int $offset Position (advanced).
     * @param int $depth Nesting.
     * @return mixed
     * @throws InvalidArgumentException
     */
    private static function item(string $data, int &$offset, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('CBOR nested too deep.');
        }
        $initial = ord(self::take($data, $offset, 1));
        $major = $initial >> 5;
        $info = $initial & 0x1f;
        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new InvalidArgumentException('Unsupported CBOR simple value.'),
            };
        }
        $value = self::length($data, $offset, $info);

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                return self::take($data, $offset, $value);
            case 4:
                $list = [];
                for ($i = 0; $i < $value; $i++) {
                    $list[] = self::item($data, $offset, $depth + 1);
                }
                return $list;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::item($data, $offset, $depth + 1);
                    if (!is_int($key) && !is_string($key)) {
                        throw new InvalidArgumentException('Unsupported CBOR map key.');
                    }
                    $map[$key] = self::item($data, $offset, $depth + 1);
                }
                return $map;
            default:
                throw new InvalidArgumentException('Unsupported CBOR type.');
        }
    }

    /**
     * @brief Reads the argument of an item (value or length).
     *
     * @param string $data Buffer.
     * @param int $offset Position (advanced).
     * @param int $info Additional information of the initial byte.
     * @return int
     * @throws InvalidArgumentException
     */
    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $size = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new InvalidArgumentException('Unsupported CBOR length.'),
        };
        $value = 0;
        foreach (str_split(self::take($data, $offset, $size)) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        if ($value < 0) {
            throw new InvalidArgumentException('CBOR value too large.');
        }

        return $value;
    }

    /**
     * @brief Takes bytes from the buffer.
     *
     * @param string $data Buffer.
     * @param int $offset Position (advanced).
     * @param int $length Bytes.
     * @return string
     * @throws InvalidArgumentException When the buffer is too short.
     */
    private static function take(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new InvalidArgumentException('CBOR data truncated.');
        }
        $bytes = substr($data, $offset, $length);
        $offset += $length;

        return $bytes;
    }
}
