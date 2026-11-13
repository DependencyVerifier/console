<?php

declare(strict_types=1);

namespace Fleet\Application\Query\View;

final readonly class ConsoleKeyRotation
{
    public function __construct(public string $current, public ?string $next, public int $approved, public int $onNext)
    {
    }

    /**
     * Every approved agent pins the next key: it may become the current one.
     */
    public function isComplete(): bool
    {
        return null !== $this->next && $this->onNext === $this->approved;
    }
}
