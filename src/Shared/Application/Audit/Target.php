<?php

declare(strict_types=1);

namespace Shared\Application\Audit;

use function Symfony\Component\String\u;

/**
 * What the action was about: a type (`user`, `team`, `invitation`…) and the id of that object.
 */
final readonly class Target
{
    private function __construct(public string $type, private ?string $id)
    {
    }

    public static function none(): self
    {
        return new self('none', null);
    }

    public static function of(string $type, string $id): self
    {
        if ([] === u($type)->match('/^[a-z][a-z_]{0,63}$/')) {
            throw new \InvalidArgumentException(\sprintf('Invalid audit target type "%s".', $type));
        }

        return new self($type, $id);
    }

    public function isNone(): bool
    {
        return null === $this->id;
    }

    public function id(): string
    {
        return $this->id ?? throw new \LogicException('The "none" target has no id.');
    }

    public function idForStorage(): ?string
    {
        return $this->id;
    }
}
