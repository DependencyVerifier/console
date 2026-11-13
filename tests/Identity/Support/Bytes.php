<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

/**
 * A CBOR byte string (VirtualAuthenticator).
 */
final readonly class Bytes
{
    public function __construct(public string $bytes)
    {
    }
}
