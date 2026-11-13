<?php

declare(strict_types=1);

namespace Notification\Application\Query\View;

final readonly class WebhookRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastCalledAt,
        public ?bool $lastSucceeded,
        public ?string $lastError,
    ) {
    }
}
