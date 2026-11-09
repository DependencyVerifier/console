<?php

declare(strict_types=1);

namespace Organization\Domain\Model;

/**
 * A team quota: unlimited or a limit. Strict blocking at 100 % (decision 13, the super admin too), warning from 80 %.
 */
final readonly class Quota
{
    public const float WARNING_RATIO = 0.8;

    private function __construct(private ?int $limit)
    {
        if (null !== $limit && $limit < 0) {
            throw new \InvalidArgumentException('A quota cannot be negative.');
        }
    }

    public static function unlimited(): self
    {
        return new self(null);
    }

    public static function of(int $limit): self
    {
        return new self($limit);
    }

    public static function fromStorage(?int $limit): self
    {
        return new self($limit);
    }

    public function isUnlimited(): bool
    {
        return null === $this->limit;
    }

    public function limit(): int
    {
        return $this->limit ?? throw new \LogicException('An unlimited quota has no limit.');
    }

    public function toStorage(): ?int
    {
        return $this->limit;
    }

    /**
     * May one more item be added on top of the current usage?
     */
    public function allows(int $usage): bool
    {
        return null === $this->limit || $usage < $this->limit;
    }

    /**
     * Usage over limit (0 when unlimited, may exceed 1 when the quota was lowered under the usage).
     */
    public function ratio(int $usage): float
    {
        if (null === $this->limit) {
            return 0.0;
        }

        return 0 === $this->limit ? ($usage > 0 ? \INF : 1.0) : $usage / $this->limit;
    }

    public function isWarning(int $usage): bool
    {
        return $this->ratio($usage) >= self::WARNING_RATIO;
    }
}
