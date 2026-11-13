<?php

declare(strict_types=1);

namespace Inventory\Application\Content;

use Inventory\Domain\Repository\AppInstanceRepository;
use Inventory\Domain\Repository\ContainerImageRepository;
use Inventory\Domain\Repository\HostInventoryRepository;
use Inventory\Domain\Repository\HostLabelRepository;
use Inventory\Domain\Repository\InventoryRunRepository;
use Inventory\Domain\Repository\SnapshotBlobRepository;
use Inventory\Domain\Repository\SnapshotRecordRepository;
use Shared\Application\Content\ProjectContentProbe;
use Shared\Application\Content\TeamContentEraser;
use Symfony\Component\Uid\Uuid;

/**
 * What Inventory holds for Organization's objects: a deleted team takes its inventories, applications, images,
 * hosts' facts and labels, history and snapshots (their packages with them); an environment with applications cannot be deleted
 * (detach them first).
 */
final readonly class InventoryTeamContent implements TeamContentEraser, ProjectContentProbe
{
    public function __construct(
        private InventoryRunRepository $runs,
        private SnapshotRecordRepository $records,
        private AppInstanceRepository $apps,
        private ContainerImageRepository $images,
        private HostInventoryRepository $hosts,
        private SnapshotBlobRepository $blobs,
        private HostLabelRepository $labels,
    ) {
    }

    public function eraseTeam(string $teamId): void
    {
        $team = Uuid::fromString($teamId);
        $this->runs->deleteOfTeam($team);
        $this->records->deleteOfTeam($team);
        $this->apps->deleteOfTeam($team);
        $this->images->deleteOfTeam($team);
        $this->hosts->deleteOfTeam($team);
        $this->blobs->deleteOfTeam($team);
        $this->labels->deleteOfTeam($team);
    }

    public function projectHasContent(string $projectId): bool
    {
        return false; // a project is deleted once it has no environment: its applications are checked there
    }

    public function environmentHasContent(string $environmentId): bool
    {
        return Uuid::isValid($environmentId) && $this->apps->countInEnvironment(Uuid::fromString($environmentId)) > 0;
    }
}
