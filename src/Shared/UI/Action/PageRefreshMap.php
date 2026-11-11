<?php

declare(strict_types=1);

namespace Shared\UI\Action;

use Shared\Application\Audit\AuditAction;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Actions whose success changes what the page shows beyond its lists and frames (counters, a creation button now
 * enabled or disabled by a quota): StreamOrRedirect answers a Turbo `refresh` instead of the usual streams, the success
 * message going through the flash bag so the refreshed page shows it.
 */
#[AutoconfigureTag]
interface PageRefreshMap
{
    public function refreshesPage(AuditAction $action): bool;
}
