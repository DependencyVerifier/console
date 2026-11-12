<?php

declare(strict_types=1);

namespace Exposure\Application\Query\View;

/**
 * One acceptance of a finding; `acceptedBy` is the email of its author.
 */
final readonly class AcceptanceView
{
    public function __construct(
        public string $justification,
        public string $acceptedBy,
        public \DateTimeImmutable $acceptedAt,
        public \DateTimeImmutable $expiresOn,
        public ?\DateTimeImmutable $revokedAt,
        public bool $active,
    ) {
    }
}
