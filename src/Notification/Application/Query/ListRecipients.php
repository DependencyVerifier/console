<?php

declare(strict_types=1);

namespace Notification\Application\Query;

use Notification\Application\Query\View\RecipientRow;
use Shared\Application\Bus\Query;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The recipients a manager attached to a project, and the addresses still invited (`project.notifications.manage`).
 *
 * @implements Query<Page<RecipientRow>>
 */
final readonly class ListRecipients implements Query
{
    public function __construct(public string $teamId, public string $projectId, public PageRequest $page)
    {
    }
}
