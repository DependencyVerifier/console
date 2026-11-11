<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Admin\Handler;

use Fleet\Application\Query\Admin\ListAllAgents;
use Fleet\Application\Query\FleetReadModel;
use Fleet\Application\Query\View\AgentRow;
use Shared\Application\Authorization\InstancePermissions;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\FindTeamNames;
use Shared\Application\Query\Page;
use Shared\Domain\Authorization\Permission;

final readonly class ListAllAgentsHandler implements QueryHandler
{
    public function __construct(private InstancePermissions $permissions, private FleetReadModel $read, private QueryBus $queries)
    {
    }

    /**
     * @return Page<AgentRow>
     */
    public function __invoke(ListAllAgents $query): Page
    {
        if (!$this->permissions->isGranted(Permission::InstanceAgentsView)) {
            throw PermissionDenied::for(Permission::InstanceAgentsView->value);
        }

        $page = $this->read->agents(null, $query->page);
        $teamIds = array_values(array_unique(array_map(static fn (AgentRow $row): string => $row->teamId, $page->items)));
        $names = [] === $teamIds ? [] : $this->queries->ask(new FindTeamNames($teamIds));

        return new Page(array_map(static fn (AgentRow $row): AgentRow => new AgentRow(
            $row->id, $row->teamId, $row->status, $row->fingerprint, $row->mode, $row->scope, $row->composeProject, $row->version,
            $row->hostId, $row->hostname, $row->enrolledAt, $row->lastSeenAt, $names[$row->teamId] ?? '',
        ), $page->items), $page->filtered, $page->total);
    }
}
