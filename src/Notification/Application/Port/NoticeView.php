<?php

declare(strict_types=1);

namespace Notification\Application\Port;

/**
 * A notice as an email shows it: what happened, where, and the subscription its unsubscribe link turns off.
 */
final readonly class NoticeView
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public string $noticeId,
        public string $event,
        public string $teamId,
        public string $teamName,
        public ?string $projectId,
        public array $content,
        public \DateTimeImmutable $occurredAt,
        public string $subscriptionId,
    ) {
    }
}
