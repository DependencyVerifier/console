<?php

declare(strict_types=1);

namespace Tests\Notification\Support;

use Notification\Application\Port\NoticeView;
use Notification\Application\Port\NotificationMailer;
use Notification\Domain\Model\Frequency;

final class RecordingNotificationMailer implements NotificationMailer
{
    /** @var list<array{string, NoticeView}> */
    public array $notices = [];
    /** @var list<array{string, Frequency, list<NoticeView>}> */
    public array $digests = [];
    public ?string $failure = null;

    public function notice(string $email, NoticeView $notice): ?string
    {
        $this->notices[] = [$email, $notice];

        return $this->failure;
    }

    public function digest(string $email, Frequency $frequency, array $notices): ?string
    {
        $this->digests[] = [$email, $frequency, $notices];

        return $this->failure;
    }
}
