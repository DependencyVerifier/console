<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Event\DomainEvent;
use Shared\Domain\Model\EventRecording;
use Shared\Domain\Model\RecordsEvents;

final class EventRecordingTest extends TestCase
{
    #[Test]
    public function it_should_release_recorded_events_once_in_order(): void
    {
        $first = new class implements DomainEvent {};
        $second = new class implements DomainEvent {};
        $aggregate = new class($first, $second) implements RecordsEvents {
            use EventRecording;

            public function __construct(DomainEvent ...$events)
            {
                foreach ($events as $event) {
                    $this->recordThat($event);
                }
            }
        };

        self::assertSame([$first, $second], $aggregate->releaseEvents());
        self::assertSame([], $aggregate->releaseEvents());
    }
}
