<?php

declare(strict_types=1);

namespace Exposure\Application\EventHandler;

use Exposure\Domain\Event\FindingEscalated;
use Exposure\Domain\Event\FindingFixed;
use Exposure\Domain\Event\FindingOpened;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\EventBus;
use Shared\Application\Bus\EventHandler;
use Shared\Application\Contract\Exposure\FindingChanged;

/**
 * Translates Exposure's internal facts into its published language (Notification).
 */
final readonly class PublishFindingChanges implements EventHandler
{
    public function __construct(private EventBus $events, private ClockInterface $clock)
    {
    }

    public function __invoke(FindingOpened|FindingFixed|FindingEscalated $event): void
    {
        $this->events->publish(new FindingChanged($event->findingId, $event->teamId, match (true) {
            $event instanceof FindingOpened => $event->reopened ? FindingChanged::REOPENED : FindingChanged::OPENED,
            $event instanceof FindingFixed => FindingChanged::FIXED,
            default => FindingChanged::ESCALATED,
        }, $this->clock->now()->format(\DATE_ATOM)));
    }
}
