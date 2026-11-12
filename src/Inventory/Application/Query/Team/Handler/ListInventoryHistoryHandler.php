<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\Team\GetApplication;
use Inventory\Application\Query\Team\ListInventoryHistory;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Domain\Model\SnapshotSubject;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

/**
 * Who sees the host or the application sees its history (404 otherwise).
 */
final readonly class ListInventoryHistoryHandler implements QueryHandler
{
    public function __construct(private InventoryVisibility $visibility, private GetApplicationHandler $applications, private InventoryReadModel $read)
    {
    }

    /**
     * @return Page<HistoryRow>
     */
    public function __invoke(ListInventoryHistory $query): Page
    {
        $subjects = match ($query->of) {
            ListInventoryHistory::APPLICATION => $this->application($query->teamId, $query->id),
            ListInventoryHistory::HOST => $this->host($query->teamId, $query->id),
            default => throw ResourceNotFound::of('history'),
        };

        return $this->read->history($subjects, $query->id, $query->page);
    }

    /**
     * @return list<SnapshotSubject>
     */
    private function application(string $teamId, string $appId): array
    {
        if (($this->applications)(new GetApplication($appId))->app->teamId !== $teamId) {
            throw ResourceNotFound::of('application');
        }

        return [SnapshotSubject::Application];
    }

    /**
     * @return list<SnapshotSubject>
     */
    private function host(string $teamId, string $hostId): array
    {
        $this->visibility->host($teamId, $hostId);

        return [SnapshotSubject::HostFacts, SnapshotSubject::HostPackages];
    }
}
