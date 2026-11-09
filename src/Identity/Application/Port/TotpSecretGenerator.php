<?php

declare(strict_types=1);

namespace Identity\Application\Port;

interface TotpSecretGenerator
{
    /**
     * A new random base32 secret (plain; the handler encrypts it before it reaches the aggregate).
     */
    public function generate(): string;
}
