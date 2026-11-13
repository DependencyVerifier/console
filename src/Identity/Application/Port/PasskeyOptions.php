<?php

declare(strict_types=1);

namespace Identity\Application\Port;

/**
 * The options of one WebAuthn ceremony (JSON, its challenge inside): given to the browser, and kept by the server
 * until the answer, which is checked against them (single use).
 */
final readonly class PasskeyOptions
{
    public function __construct(public string $json)
    {
    }
}
