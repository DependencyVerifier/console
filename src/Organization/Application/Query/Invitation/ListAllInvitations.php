<?php

declare(strict_types=1);

namespace Organization\Application\Query\Invitation;

use Organization\Application\Query\View\InvitationRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Every pending invitation of the instance, with its team and project (super admin, /admin/users).
 *
 * @implements Query<Page<InvitationRow>>
 */
final readonly class ListAllInvitations implements Query
{
    public function __construct(public PageRequest $page = new PageRequest())
    {
    }
}
