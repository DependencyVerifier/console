<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Application\Query\InventoryReadModel;
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
 * The read model as lists, filtered like the DBAL one; it records the last applications() filter.
 */
final class InMemoryReadModel implements InventoryReadModel
{
    /** @var list<AppRow> */
    public array $apps = [];

    /** @var list<HostView> */
    public array $hosts = [];

    /** @var list<ImageView> */
    public array $images = [];

    /** @var array<string, list<PackageRow>> digest => packages */
    public array $packages = [];

    /** @var array<string, list<HistoryRow>> subject id => records, the latest first */
    public array $history = [];

    /** @var array<string, mixed> */
    public array $lastFilter = [];

    public function applications(string $teamId, ?array $environmentIds, bool $unattachedOnly, ?string $hostId, ?string $imageId, PageRequest $page): Page
    {
        $this->lastFilter = ['environments' => $environmentIds, 'unattached' => $unattachedOnly, 'host' => $hostId, 'image' => $imageId];
        $rows = array_values(array_filter($this->apps, static fn (AppRow $r): bool => $r->teamId === $teamId
            && (null === $environmentIds || \in_array($r->environmentId, $environmentIds, true))
            && (!$unattachedOnly || null === $r->environmentId)
            && (null === $hostId || $r->hostId === $hostId)
            && (null === $imageId || $r->imageId === $imageId)));

        return new Page($rows, \count($rows), \count($rows));
    }

    public function application(string $appId): ?AppRow
    {
        return array_find($this->apps, static fn (AppRow $r): bool => $r->id === $appId);
    }

    public function counts(string $teamId, ?array $environmentIds): array
    {
        $present = array_filter($this->apps, static fn (AppRow $r): bool => $r->teamId === $teamId && $r->present);

        return [
            'applications' => \count(array_filter($present, static fn (AppRow $r): bool => null === $environmentIds || \in_array($r->environmentId, $environmentIds, true))),
            'unattached' => \count(array_filter($present, static fn (AppRow $r): bool => null === $r->environmentId)),
            'images' => \count(array_filter($this->images, static fn (ImageView $i): bool => $i->teamId === $teamId)),
        ];
    }

    public function packages(string $teamId, string $digest, PageRequest $page): Page
    {
        $rows = $this->packages[$teamId.' '.$digest] ?? [];

        return new Page($rows, \count($rows), \count($rows));
    }

    public function history(array $subjects, string $subjectId, PageRequest $page): Page
    {
        $names = array_map(static fn (SnapshotSubject $s): string => $s->value, $subjects);
        $rows = array_values(array_filter($this->history[$subjectId] ?? [], static fn (HistoryRow $r): bool => \in_array($r->subject, $names, true)));

        return new Page($rows, \count($rows), \count($rows));
    }

    public function host(string $hostId): ?HostView
    {
        return array_find($this->hosts, static fn (HostView $h): bool => $h->hostId === $hostId);
    }

    /** @var array<string, list<array{purl: string, direct: bool}>> team => packages */
    public array $teamPackages = [];

    public function teamPackages(string $teamId): array
    {
        return $this->teamPackages[$teamId] ?? [];
    }

    /** @var array<string, list<TeamSubject>> */
    public array $teamSubjects = [];

    public function teamSubjects(string $teamId): array
    {
        return $this->teamSubjects[$teamId] ?? [];
    }

    public function inventoriedTeams(): array
    {
        return array_keys($this->teamSubjects);
    }

    public function image(string $imageRecordId): ?ImageView
    {
        return array_find($this->images, static fn (ImageView $i): bool => $i->id === $imageRecordId);
    }

    public function imageByImageId(string $teamId, string $imageId): ?ImageView
    {
        return array_find($this->images, static fn (ImageView $i): bool => $i->teamId === $teamId && $i->imageId === $imageId);
    }
}
