<?php

declare(strict_types=1);

namespace Notification\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Action\PageRefreshMap;

use function Symfony\Component\String\u;

/**
 * A notification gesture changes the lists and the forms of the page (the webhooks a rule offers, one's own
 * frequency): the page is refreshed, the message goes through the flash bag.
 */
final class NotificationPageRefresh implements PageRefreshMap
{
    public function refreshesPage(AuditAction $action): bool
    {
        return u($action->name)->startsWith('notification.');
    }
}
