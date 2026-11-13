<?php

declare(strict_types=1);

namespace Inventory\Application\Query\Team\Handler;

use Inventory\Application\Query\InventoryReadModel;
use Inventory\Application\Query\Team\GetEnvironmentMatrix;
use Inventory\Application\Query\View\MatrixEntry;
use Inventory\Application\Query\View\MatrixRow;
use Inventory\Application\Security\InventoryAccess;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Query\Page;

use function Symfony\Component\String\u;

/**
 * Groups the components of an environment by version: runtimes first, then OS, then direct dependencies, each by
 * name then version; a component with several versions drifts.
 */
final readonly class GetEnvironmentMatrixHandler implements QueryHandler
{
    private const array KINDS = [MatrixRow::RUNTIME, MatrixRow::OS, MatrixRow::PACKAGE];

    public function __construct(private InventoryAccess $access, private InventoryReadModel $read)
    {
    }

    /**
     * @return Page<MatrixRow>
     */
    public function __invoke(GetEnvironmentMatrix $query): Page
    {
        $scope = $this->access->scope($query->teamId);
        if (!isset($scope->environments[$query->environmentId]) || !$scope->seesEnvironment($query->environmentId)) {
            throw ResourceNotFound::of('environment');
        }

        /** @var array<string, array{kind: string, ecosystem: string, component: string, versions: array<string, list<array{appId: string, application: string, hostId: string, host: string}>>}> $components */
        $components = [];
        foreach ($this->read->matrixEntries($query->teamId, $query->environmentId) as $entry) {
            $key = $entry->kind.' '.$entry->ecosystem.' '.$entry->component;
            $components[$key] ??= ['kind' => $entry->kind, 'ecosystem' => $entry->ecosystem, 'component' => $entry->component, 'versions' => []];
            $components[$key]['versions'][$entry->version][] = self::place($entry);
        }

        $rows = [];
        foreach ($components as $component) {
            foreach ($component['versions'] as $version => $places) {
                $rows[] = new MatrixRow($query->teamId, $component['kind'], $component['ecosystem'], $component['component'], (string) $version, $places, \count($component['versions']));
            }
        }

        usort($rows, static fn (MatrixRow $a, MatrixRow $b): int => [array_search($a->kind, self::KINDS, true), u($a->component)->lower()->toString(), $a->ecosystem]
            <=> [array_search($b->kind, self::KINDS, true), u($b->component)->lower()->toString(), $b->ecosystem] ?: version_compare($a->version, $b->version));

        $search = u($query->page->search)->trim()->lower();
        $filtered = array_values(array_filter($rows, static fn (MatrixRow $row): bool => (!$query->driftOnly || $row->drifts())
            && ($search->isEmpty() || u($row->component)->lower()->containsAny($search->toString()))));

        return new Page(\array_slice($filtered, $query->page->offset, $query->page->limit), \count($filtered), \count($rows));
    }

    /**
     * @return array{appId: string, application: string, hostId: string, host: string}
     */
    private static function place(MatrixEntry $entry): array
    {
        return ['appId' => $entry->appId, 'application' => $entry->application, 'hostId' => $entry->hostId, 'host' => $entry->host];
    }
}
