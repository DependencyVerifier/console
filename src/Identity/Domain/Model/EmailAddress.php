<?php

declare(strict_types=1);

namespace Identity\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\String\u;

/**
 * Normalized email (trimmed, lower case, 254 characters at most), unique per account.
 */
#[ORM\Embeddable]
final class EmailAddress
{
    public const int MAX_LENGTH = 254;

    private function __construct(
        #[ORM\Column(name: 'email', length: self::MAX_LENGTH, unique: true)]
        private string $value,
    ) {
    }

    public static function of(string $raw): self
    {
        $normalized = u($raw)->trim()->lower()->toString();
        if (u($normalized)->length() > self::MAX_LENGTH || false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidEmailAddress();
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
