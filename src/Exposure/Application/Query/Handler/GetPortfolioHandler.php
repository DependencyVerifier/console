<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Handler;

use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\ExposureScope;
use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\GetPortfolio;
use Exposure\Application\Query\GetProductionExposure;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Application\Query\View\Portfolio;
use Exposure\Application\Query\View\TeamProduction;
use Exposure\Application\Security\FindingAccess;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\QueryBus;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Organization\ListActorTeams;
use Shared\Application\Contract\Organization\ListTeamEnvironments;

final readonly class GetPortfolioHandler implements QueryHandler
{
    public function __construct(private QueryBus $queries, private FindingAccess $access, private FindingReadModel $read, private ClockInterface $clock)
    {
    }

    public function __invoke(GetPortfolio $query): Portfolio
    {
        $teams = [];
        $urgent = [];
        foreach ($this->queries->ask(new ListActorTeams()) as $team) {
            $production = $this->queries->ask(new GetProductionExposure($team->teamId, ExposureScope::Team, null, GetPortfolio::URGENT));
            $scoped = $this->access->filter($team->teamId, ExposureScope::Team, null);
            $figures = $this->read->projectFigures(new ExposureFilter($scoped->teamId, $scoped->projectIds, production: true), $this->clock->now());
            $projects = [];
            foreach ($this->queries->ask(new ListTeamEnvironments($team->teamId)) as $environment) {
                if ($environment->production && (null === $scoped->projectIds || \in_array($environment->projectId, $scoped->projectIds, true))) {
                    $projects[$environment->projectId] = ['projectId' => $environment->projectId, 'name' => $environment->projectName, 'knownExploited' => 0, 'critical' => 0, 'high' => 0, 'endsOfLife' => 0, ...$figures[$environment->projectId] ?? []];
                }
            }

            $teams[] = new TeamProduction($team->teamId, $team->name, $team->role, $production, array_values($projects));
            $urgent = [...$urgent, ...$production->urgent];
        }

        usort($urgent, self::urgency(...));

        return new Portfolio($teams, \array_slice($urgent, 0, GetPortfolio::URGENT));
    }

    /**
     * The order of FindingReadModel::urgent(), across the teams: exploited, critical, high, then ends of life
     * reached, imminent, soon.
     */
    private static function urgency(FindingRow $a, FindingRow $b): int
    {
        $severity = static fn (FindingRow $f): int => self::rank($f->severity, ['critical', 'high']);
        $eol = static fn (FindingRow $f): int => self::rank($f->eolLevel, ['expired', 'imminent', 'soon']);

        return [!$a->knownExploited, $severity($a), $eol($a), 'vulnerability' === $a->kind ? 0 : 1, -($a->cvss ?? -1.0), $a->title, $a->id]
            <=> [!$b->knownExploited, $severity($b), $eol($b), 'vulnerability' === $b->kind ? 0 : 1, -($b->cvss ?? -1.0), $b->title, $b->id];
    }

    /**
     * The position of a value in an order, the values out of it last.
     *
     * @param list<string> $order
     */
    private static function rank(?string $value, array $order): int
    {
        return array_flip($order)[$value ?? ''] ?? \count($order);
    }
}
