<?php

declare(strict_types=1);

namespace Notification\Application\Port;

use Notification\Domain\Model\Frequency;

/**
 * Sends the notification emails, synchronously. Never throws: a failure is returned (short, without content) and
 * counted as an attempt by the delivery.
 */
interface NotificationMailer
{
    /**
     * @return ?string the failure, null once sent
     */
    public function notice(string $email, NoticeView $notice): ?string;

    /**
     * @param non-empty-list<NoticeView> $notices by team, then scope, the oldest first
     *
     * @return ?string the failure, null once sent
     */
    public function digest(string $email, Frequency $frequency, array $notices): ?string;
}
