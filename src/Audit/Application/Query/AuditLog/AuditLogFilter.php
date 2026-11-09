<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

use Shared\Application\Audit\OutcomeKind;

/**
 * Whitelisted filters, bound as parameters by the reader. The team always comes from the route (after the voter),
 * never from the query string.
 */
final readonly class AuditLogFilter
{
    public function __construct(public ?string $teamId = null, public ?OutcomeKind $outcome = null)
    {
    }
}
