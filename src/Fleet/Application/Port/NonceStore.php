<?php

declare(strict_types=1);

namespace Fleet\Application\Port;

/**
 * Replay protection of the signed requests (protocol §4.3 step 6): a (keyid, nonce) pair is accepted once within
 * 10 minutes.
 */
interface NonceStore
{
    /**
     * @phpstan-impure
     *
     * @return bool false when the pair was already seen
     */
    public function remember(string $keyId, string $nonce, \DateTimeImmutable $now): bool;
}
