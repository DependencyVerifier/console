<?php

declare(strict_types=1);

namespace Organization\Application\Query\Invitation\Handler;

use Organization\Application\Query\OrganizationReadModel;
use Psr\Clock\ClockInterface;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\FindPendingInvitation;
use Shared\Application\Contract\Organization\PendingInvitation;

/**
 * Public step of the invitation flow: knowing the token (or the id kept in the session) is the authorization.
 */
final readonly class FindPendingInvitationHandler implements QueryHandler
{
    public function __construct(private OrganizationReadModel $read, private ClockInterface $clock)
    {
    }

    public function __invoke(FindPendingInvitation $query): PendingInvitation
    {
        return $this->read->pendingInvitation($query->tokenHash, $query->invitationId, $this->clock->now())
            ?? throw ResourceNotFound::of('invitation');
    }
}
