<?php

declare(strict_types=1);

namespace Tests\Fixtures\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;

/**
 * Test-only aggregate (table created by Tests\Support\FixtureSchema) to exercise the command bus middlewares.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_aggregate')]
class FixtureAggregate implements RecordsEvents
{
    use EventRecording;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        public readonly string $id,
        #[ORM\Column(length: 64)]
        public string $name,
    ) {
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->recordThat(new FixtureEvent($this->id, 'renamed'));
    }

    /**
     * Records a fact without changing any field: the collector must still publish it.
     */
    public function touch(): void
    {
        $this->recordThat(new FixtureEvent($this->id, 'touched'));
    }
}
