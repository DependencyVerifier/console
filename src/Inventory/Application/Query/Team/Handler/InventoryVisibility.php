<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Security\InventoryAccess;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Fleet\FindTeamHost;
use Shared\Application\Query\PageRequest;
use Symfony\Component\Uid\Uuid;

/**
 * A host or an image of the team (404 otherwise), seen by a member, or by a TEAM_GUEST when it carries (runs) an
 * application of one of its projects.
 */
final readonly class InventoryVisibility
{
    public function __construct(private InventoryAccess $access, private InventoryReadModel $read, private QueryBus $queries)
    {
    }

    /**
     * A host of the team never inventoried yet (its agent just enrolled) is an empty page for a member.
     */
    public function host(string $teamId, string $hostId): HostView
    {
        $host = Uuid::isValid($hostId) ? $this->read->host($hostId) : null;
        if (null === $host && $this->access->scope($teamId)->seesAll()) {
            $hostname = $this->queries->ask(new FindTeamHost($teamId, $hostId));
            $label = $this->read->hostLabel($hostId);
            $host = null === $hostname ? null : new HostView($hostId, $teamId, $hostname, [], null, null, 0, $label['displayName'] ?? null, $label['addresses'] ?? []);
        }

        if (null === $host || $host->teamId !== $teamId || !$this->seesSomeApplication($teamId, $hostId, null)) {
            throw ResourceNotFound::of('host');
        }

        return $host;
    }

    public function image(string $teamId, string $imageRecordId): ImageView
    {
        $image = Uuid::isValid($imageRecordId) ? $this->read->image($imageRecordId) : null;
        if (null === $image || $image->teamId !== $teamId || !$this->seesSomeApplication($teamId, null, $image->imageId)) {
            throw ResourceNotFound::of('image');
        }

        return $image;
    }

    private function seesSomeApplication(string $teamId, ?string $hostId, ?string $imageId): bool
    {
        $scope = $this->access->scope($teamId);

        return $scope->seesAll() || $this->read->applications($teamId, $scope->visibleEnvironments, false, $hostId, $imageId, new PageRequest())->total > 0;
    }
}
