<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use function Symfony\Component\String\u;

/**
 * `sha256:` + 64 lowercase hex characters, the SHA-256 of a snapshot's uncompressed bytes (protocol §8.1).
 */
final readonly class Digest
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        if ([] === u($value)->match('/^sha256:[0-9a-f]{64}$/')) {
            throw new InvalidInventory('Invalid digest.');
        }

        return new self($value);
    }

    public static function ofContent(string $bytes): self
    {
        return new self('sha256:'.hash('sha256', $bytes));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
