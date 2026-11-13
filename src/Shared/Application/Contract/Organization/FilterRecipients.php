<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Organization;

use Shared\Application\Bus\Query;

/**
 * Published query of Organization: among accounts, those with an active membership of the team that see the project
 * (every project of the team when null: the fleet). What a notification may tell them.
 *
 * @implements Query<list<string>>
 */
final readonly class FilterRecipients implements Query
{
    /**
     * @param list<string> $userIds
     */
    public function __construct(public string $teamId, public ?string $projectId, public array $userIds)
    {
    }
}
