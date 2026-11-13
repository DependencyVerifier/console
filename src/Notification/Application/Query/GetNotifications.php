<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Notification\Application\Query\View\NotificationsView;
use Shared\Application\Bus\Query;

/**
 * The notifications of a project, or of the fleet of the team (no project): 404 out of sight, 403 for the fleet of a TEAM_GUEST.
 *
 * @implements Query<NotificationsView>
 */
final readonly class GetNotifications implements Query
{
    public function __construct(public string $teamId, public ?string $projectId)
    {
    }
}
