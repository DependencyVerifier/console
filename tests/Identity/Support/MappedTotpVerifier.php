<?php

declare(strict_types=1);

namespace Tests\Identity\Support;

use Identity\Domain\Model\UserId;
use Identity\Domain\Security\TotpVerifier;

/**
 * Code → time step; records what it was asked.
 */
final class MappedTotpVerifier implements TotpVerifier
{
    /** @var list<array{string, string, string}> */
    public array $asked = [];

    /**
     * @param array<int|string, int> $steps (numeric string keys become integers)
     */
    public function __construct(private readonly array $steps)
    {
    }

    public function matchingStep(string $encryptedSecret, UserId $owner, string $code, \DateTimeImmutable $now): ?int
    {
        $this->asked[] = [$encryptedSecret, $owner->toString(), $code];

        return $this->steps[$code] ?? null;
    }
}
