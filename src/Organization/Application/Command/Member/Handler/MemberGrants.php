<?php

declare(strict_types=1);

namespace Organization\Application\Command\Member\Handler;

use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Model\GrantChange;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Repository\PermissionGrantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The business history of role and permission changes, written in the same transaction as the change and its audit.
 */
final readonly class MemberGrants
{
    public function __construct(private PermissionGrantRepository $grants, private OrganizationAccess $access, private ClockInterface $clock)
    {
    }

    public function record(TeamId $teamId, ?ProjectId $projectId, Uuid $userId, GrantChange $change, string $value): void
    {
        $this->grants->add(PermissionGrant::record($teamId, $projectId, $userId, $change, $value, $this->access->actorIdOrNull(), $this->clock->now()));
    }
}
