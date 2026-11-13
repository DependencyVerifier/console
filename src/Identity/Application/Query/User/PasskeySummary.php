<?php

declare(strict_types=1);

namespace Identity\Application\Query\User;

/**
 * A passkey of an account as its holder sees it: never its key, nor its credential id.
 */
final readonly class PasskeySummary
{
    public function __construct(
        public string $id,
        public string $name,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt,
    ) {
    }
}
