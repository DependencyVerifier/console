<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Application\Port\NonceStore;

final class RecordingNonces implements NonceStore
{
    /** @var array<string, \DateTimeImmutable> */
    public array $seen = [];

    public function remember(string $keyId, string $nonce, \DateTimeImmutable $now): bool
    {
        if (isset($this->seen[$keyId.' '.$nonce])) {
            return false;
        }

        $this->seen[$keyId.' '.$nonce] = $now;

        return true;
    }
}
