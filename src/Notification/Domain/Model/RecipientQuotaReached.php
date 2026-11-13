<?php

declare(strict_types=1);

namespace Notification\Domain\Model;

use Shared\Domain\BusinessRuleViolated;

/**
 * `maxNotificationOnlyAccounts` of the team is reached (the same refusal as every quota: `rule:quota`).
 */
final class RecipientQuotaReached extends BusinessRuleViolated
{
    public function __construct(int $limit)
    {
        parent::__construct('quota', \sprintf('Quota "max_notification_only_accounts" of %d reached.', $limit));
    }
}
