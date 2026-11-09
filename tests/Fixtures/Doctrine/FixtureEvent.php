<?php

declare(strict_types=1);

namespace Tests\Fixtures\Doctrine;

use Shared\Domain\Event\DomainEvent;

final readonly class FixtureEvent implements DomainEvent
{
    public function __construct(public string $id, public string $what)
    {
    }
}
