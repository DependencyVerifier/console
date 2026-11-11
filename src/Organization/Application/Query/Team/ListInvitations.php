<?php

declare(strict_types=1);

namespace Organization\Application\Query\Team;

use Organization\Application\Query\View\InvitationRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * @implements Query<Page<InvitationRow>>
 */
final readonly class ListInvitations implements Query
{
    /**
     * @param string|null $projectId the invitations of one project of the team, else all those of the team
     */
    public function __construct(public string $teamId, public PageRequest $page, public ?string $projectId = null)
    {
    }
}
