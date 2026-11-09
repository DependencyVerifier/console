<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * `active` or `disabled(since)`. Accounts are deactivated, never deleted (decision 21).
 */
#[ORM\Embeddable]
final class AccountStatus
{
    private const string ACTIVE = 'active';
    private const string DISABLED = 'disabled';

    private function __construct(
        #[ORM\Column(name: 'status', length: 16)]
        private string $kind,
        #[ORM\Column(name: 'disabled_since', nullable: true)]
        private ?\DateTimeImmutable $disabledSince,
    ) {
    }

    public static function active(): self
    {
        return new self(self::ACTIVE, null);
    }

    public static function disabled(\DateTimeImmutable $since): self
    {
        return new self(self::DISABLED, $since);
    }

    public function isActive(): bool
    {
        return self::ACTIVE === $this->kind;
    }

    public function disabledSince(): \DateTimeImmutable
    {
        if ($this->isActive() || null === $this->disabledSince) {
            throw new \LogicException('An active account has no deactivation date.');
        }

        return $this->disabledSince;
    }
}
