<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\View\FindingRow;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Query\Page;

/**
 * Names where the findings are (`Project / environment`) at reading time, so a renamed project or environment shows
 * at once; asked once per page.
 */
final readonly class FindingPlaces
{
    public function __construct(private QueryBus $queries)
    {
    }

    /**
     * @param Page<FindingRow> $page
     *
     * @return Page<FindingRow>
     */
    public function page(string $teamId, Page $page): Page
    {
        return new Page($this->rows($teamId, $page->items), $page->filtered, $page->total);
    }

    /**
     * @param list<FindingRow> $rows
     *
     * @return list<FindingRow>
     */
    public function rows(string $teamId, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $names = [];
        foreach ($this->queries->ask(new ListTeamEnvironments($teamId)) as $environment) {
            $names[$environment->environmentId] = $environment->projectName.' / '.$environment->name;
        }

        return array_map(static fn (FindingRow $row): FindingRow => $row->withEnvironment($names[$row->environmentId ?? ''] ?? ''), $rows);
    }
}
