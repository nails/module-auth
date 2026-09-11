<?php

namespace Tests\Auth\Stub;

use InvalidArgumentException;

/**
 * A minimal CBOR encoder, sufficient to synthesise the structures a real
 * authenticator produces.
 *
 * It covers only the major types WebAuthn uses - unsigned and negative integers,
 * byte strings, text strings, arrays and maps - and always emits the shortest
 * possible length encoding, which is what the decoder under test expects.
 */
final class Cbor
{
    private const MAJOR_UNSIGNED_INT = 0;
    private const MAJOR_NEGATIVE_INT = 1;
    private const MAJOR_BYTE_STRING  = 2;
    private const MAJOR_TEXT_STRING  = 3;
    private const MAJOR_ARRAY        = 4;
    private const MAJOR_MAP          = 5;

    // --------------------------------------------------------------------------

    /**
     * Wraps a string so it is encoded as a byte string rather than as text
     */
    public static function bytes(string $sValue): CborBytes
    {
        return new CborBytes($sValue);
    }

    // --------------------------------------------------------------------------

    /**
     * Wraps an array so it is encoded as a map even when it is empty
     *
     * @param array<int|string, mixed> $aValue
     */
    public static function map(array $aValue): CborMap
    {
        return new CborMap($aValue);
    }

    // --------------------------------------------------------------------------

    /**
     * Encodes a value as CBOR
     *
     * @param mixed $mValue
     */
    public static function encode($mValue): string
    {
        if ($mValue instanceof CborMap) {
            return self::encodeMap($mValue->aValue);

        } elseif ($mValue instanceof CborBytes) {
            return self::head(self::MAJOR_BYTE_STRING, strlen($mValue->sValue)) . $mValue->sValue;

        } elseif (is_int($mValue)) {
            return $mValue >= 0
                ? self::head(self::MAJOR_UNSIGNED_INT, $mValue)
                : self::head(self::MAJOR_NEGATIVE_INT, -1 - $mValue);

        } elseif (is_string($mValue)) {
            return self::head(self::MAJOR_TEXT_STRING, strlen($mValue)) . $mValue;

        } elseif (is_array($mValue)) {
            return array_is_list($mValue)
                ? self::encodeArray($mValue)
                : self::encodeMap($mValue);
        }

        throw new InvalidArgumentException(
            sprintf('Cannot CBOR encode a value of type %s', get_debug_type($mValue))
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Encodes a map, preserving the order the keys were given in
     *
     * @param array<int|string, mixed> $aValue
     */
    public static function encodeMap(array $aValue): string
    {
        $sOut = self::head(self::MAJOR_MAP, count($aValue));

        foreach ($aValue as $mKey => $mItem) {
            $sOut .= self::encode($mKey) . self::encode($mItem);
        }

        return $sOut;
    }

    // --------------------------------------------------------------------------

    /**
     * @param array<int, mixed> $aValue
     */
    public static function encodeArray(array $aValue): string
    {
        $sOut = self::head(self::MAJOR_ARRAY, count($aValue));

        foreach ($aValue as $mItem) {
            $sOut .= self::encode($mItem);
        }

        return $sOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Emits an item header: the major type, plus the shortest encoding of the value
     */
    private static function head(int $iMajor, int $iValue): string
    {
        $iPrefix = $iMajor << 5;

        if ($iValue < 24) {
            return chr($iPrefix | $iValue);

        } elseif ($iValue <= 0xFF) {
            return chr($iPrefix | 24) . chr($iValue);

        } elseif ($iValue <= 0xFFFF) {
            return chr($iPrefix | 25) . pack('n', $iValue);

        } elseif ($iValue <= 0xFFFFFFFF) {
            return chr($iPrefix | 26) . pack('N', $iValue);
        }

        return chr($iPrefix | 27) . pack('J', $iValue);
    }
}
