<?php

namespace Tests\Auth\Stub;

/**
 * Marks a string as a CBOR byte string; without it a string encodes as text.
 */
final class CborBytes
{
    public function __construct(public readonly string $sValue)
    {
    }
}
