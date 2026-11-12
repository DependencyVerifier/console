<?php

declare(strict_types=1);

namespace Exposure\UI\Frame;

use Shared\Application\Audit\AuditAction;
use Shared\UI\Action\PageRefreshMap;

/**
 * Accepting or revoking changes the header and the history of the finding page: it is refreshed.
 */
final class ExposurePageRefresh implements PageRefreshMap
{
    private const array ACTIONS = ['exposure.accept_risk', 'exposure.revoke_risk_acceptance'];

    public function refreshesPage(AuditAction $action): bool
    {
        return \in_array($action->name, self::ACTIONS, true);
    }
}
