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
    public function __construct(public string $teamId, public PageRequest $page)
    {
    }
}
