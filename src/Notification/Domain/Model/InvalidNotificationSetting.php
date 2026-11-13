<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * A rule without event, a threshold out of range, a webhook not in HTTPS….
 */
final class InvalidNotificationSetting extends BusinessRuleViolated
{
    public function __construct(string $detail = '')
    {
        parent::__construct('invalid_notification_setting', $detail);
    }
}
