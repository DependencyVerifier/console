<?php

declare(strict_types=1);

namespace Audit\Application\Query\AuditLog;

use Shared\Application\Audit\OutcomeKind;
use Shared\Application\Bus\Query;

/**
 * Global audit (no team: `instance.audit.view`) or a team's audit (`team.audit.view` on that team).
 *
 * @implements Query<AuditPage>
 */
final readonly class ListAuditEntries implements Query
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        public ?string $teamId = null,
        public ?OutcomeKind $outcome = null,
        public ?string $cursor = null,
    ) {
    }
}
