<?php

namespace Tests\Auth\Stub;

/**
 * Marks an array as a CBOR map; without it an empty array is indistinguishable
 * from an empty list and would encode as an array.
 */
final class CborMap
{
    /**
     * @param array<int|string, mixed> $aValue
     */
    public function __construct(public readonly array $aValue)
    {
    }
}
