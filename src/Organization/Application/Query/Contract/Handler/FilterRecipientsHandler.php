<?php

declare(strict_types=1);

namespace Organization\Application\Query\Contract\Handler;

use Organization\Application\Security\MemberPermissions;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\FilterRecipients;

final readonly class FilterRecipientsHandler implements QueryHandler
{
    public function __construct(private MemberPermissions $members)
    {
    }

    /**
     * @return list<string>
     */
    public function __invoke(FilterRecipients $query): array
    {
        return array_values(array_filter($query->userIds, function (string $userId) use ($query): bool {
            $permissions = $this->members->of($userId);

            return null === $query->projectId ? $permissions->seesAllProjectsOf($query->teamId) : $permissions->seesProject($query->teamId, $query->projectId);
        }));
    }
}
