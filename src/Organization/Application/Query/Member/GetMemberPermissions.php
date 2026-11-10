<?php

declare(strict_types=1);

namespace Organization\Application\Query\Member;

use Organization\Application\Query\View\MemberPermissions;
use Shared\Application\Bus\Query;

/**
 * The extra permissions (○ of docs/cadrage.md §7bis) a member may receive on top of their role, on the team
 * (projectId null) or on a project, for the "Permissions" dialog.
 *
 * @implements Query<MemberPermissions>
 */
final readonly class GetMemberPermissions implements Query
{
    public function __construct(public string $teamId, public ?string $projectId, public string $userId)
    {
    }
}
