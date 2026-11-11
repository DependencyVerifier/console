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

    public function purgeBefore(\DateTimeImmutable $before): int
    {
        $count = \count($this->seen);
        $this->seen = array_filter($this->seen, static fn (\DateTimeImmutable $seen): bool => $seen >= $before);

        return $count - \count($this->seen);
    }
}
