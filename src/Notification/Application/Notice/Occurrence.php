<?php

declare(strict_types=1);

namespace Notification\Application\Notice;

use Notification\Domain\Model\NotificationSubject;

/**
 * Something that happened in a team, as the rules judge it, with the key telling this occurrence apart from any other
 * (an event handled twice is told once) and what a notification tells of it.
 */
final readonly class Occurrence
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(public string $teamId, public NotificationSubject $subject, public string $dedupeKey, public array $content)
    {
    }
}
