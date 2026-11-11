<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class TokenRow
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public ?string $environmentId,
        public int $uses,
        public int $maxUses,
        public \DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $createdAt,
        public bool $usable,
        public string $environment = '',
    ) {
    }

    public function withEnvironment(string $environment): self
    {
        return new self($this->id, $this->kind, $this->status, $this->environmentId, $this->uses, $this->maxUses, $this->expiresAt, $this->createdAt, $this->usable, $environment);
    }
}
