<?php

declare(strict_types=1);

namespace Inventory\Application\Query;

use Inventory\Application\Query\View\AppRow;
use Inventory\Application\Query\View\HistoryRow;
use Inventory\Application\Query\View\HostView;
use Inventory\Application\Query\View\ImageView;
use Inventory\Application\Query\View\PackageRow;
use Inventory\Domain\Model\SnapshotSubject;
use Shared\Application\Contract\Inventory\TeamSubject;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Read side of Inventory (DBAL). It filters by what it is given; the handlers decide what the actor may see.
 */
interface InventoryReadModel
{
    /**
     * @param list<string>|null $environmentIds null: any environment (attached or not)
     *
     * @return Page<AppRow> rows with an empty environment name (filled by the handler)
     */
    public function applications(string $teamId, ?array $environmentIds, bool $unattachedOnly, ?string $hostId, ?string $imageId, PageRequest $page): Page;

    public function application(string $appId): ?AppRow;

    /**
     * Applications present (in the given environments, null: any), present ones not attached, images of the team.
     *
     * @param list<string>|null $environmentIds
     *
     * @return array{applications: int, unattached: int, images: int}
     */
    public function counts(string $teamId, ?array $environmentIds): array;

    /**
     * @return Page<PackageRow>
     */
    public function packages(string $teamId, string $digest, PageRequest $page): Page;

    /**
     * The records of a subject, the latest first, with the trigger of their inventory.
     *
     * @param list<SnapshotSubject> $subjects
     *
     * @return Page<HistoryRow>
     */
    public function history(array $subjects, string $subjectId, PageRequest $page): Page;

    public function host(string $hostId): ?HostView;

    /**
     * The packages of the current SBOMs of a team (its hosts, its applications still found, its images), each package
     * URL once, direct when one SBOM says so.
     *
     * @return list<array{purl: string, direct: bool}>
     */
    public function teamPackages(string $teamId): array;

    /**
     * The applications still found and the inventoried hosts of a team, with what they run.
     *
     * @return list<TeamSubject>
     */
    public function teamSubjects(string $teamId): array;

    /**
     * @return list<string> the teams with an inventoried host
     */
    public function inventoriedTeams(): array;

    public function image(string $imageRecordId): ?ImageView;

    public function imageByImageId(string $teamId, string $imageId): ?ImageView;
}
