<?php

declare(strict_types=1);

namespace Tests\Exposure\Support;

use Exposure\Application\Query\ExposureFilter;
use Exposure\Application\Query\FindingCriteria;
use Exposure\Application\Query\FindingReadModel;
use Exposure\Application\Query\View\ExposureSummary;
use Exposure\Application\Query\View\FindingRow;
use Exposure\Domain\Model\Finding;
use Exposure\Domain\Model\RiskAcceptance;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * The findings of the in-memory repositories as rows, in the order they were added; records the last subject asked.
 */
final class InMemoryFindingReadModel implements FindingReadModel
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly InMemoryFindings $findings, private readonly InMemoryRiskAcceptances $acceptances)
    {
    }

    public function ofSubject(string $teamId, string $subjectType, string $subjectId, \DateTimeImmutable $today, PageRequest $page): Page
    {
        $this->asked[] = $subjectType.' '.$subjectId;
        $rows = array_map(fn (Finding $f): FindingRow => $this->row($f, $today), array_values(array_filter($this->findings->findings, static fn (Finding $f): bool => $f->teamId()->toRfc4122() === $teamId
            && $f->subjectType()->value === $subjectType && $f->subjectId()->toRfc4122() === $subjectId)));

        return new Page($rows, \count($rows), \count($rows));
    }

    /** @var list<int> the limits of urgent() */
    public array $limits = [];

    /** @var list<array{ExposureFilter, ?FindingCriteria}> what the handlers asked */
    public array $filters = [];

    public function list(ExposureFilter $filter, FindingCriteria $criteria, \DateTimeImmutable $today, PageRequest $page): Page
    {
        $this->filters[] = [$filter, $criteria];
        $rows = array_map(fn (Finding $f): FindingRow => $this->row($f, $today), array_values(array_filter($this->findings->findings, static fn (Finding $f): bool => $f->teamId()->toRfc4122() === $filter->teamId)));

        return new Page($rows, \count($rows), \count($rows));
    }

    public function summary(ExposureFilter $filter, \DateTimeImmutable $today): ExposureSummary
    {
        $this->filters[] = [$filter, null];

        return new ExposureSummary([], 0, [], 0, 0, [], [], []);
    }

    public function urgent(ExposureFilter $filter, \DateTimeImmutable $today, int $limit): array
    {
        $this->filters[] = [$filter, null];
        $this->limits[] = $limit;
        $rows = array_map(fn (Finding $f): FindingRow => $this->row($f, $today), array_values(array_filter($this->findings->findings, static fn (Finding $f): bool => $f->teamId()->toRfc4122() === $filter->teamId)));

        return \array_slice($rows, 0, $limit);
    }

    /** @var array<string, array{knownExploited: int, critical: int, high: int, endsOfLife: int}> */
    public array $projectFigures = [];

    public function projectFigures(ExposureFilter $filter, \DateTimeImmutable $today): array
    {
        $this->filters[] = [$filter, null];

        return $this->projectFigures;
    }

    public function hosts(ExposureFilter $filter): array
    {
        $this->filters[] = [$filter, null];

        return ['h1' => 'web-01'];
    }

    public function find(string $findingId, \DateTimeImmutable $today): ?FindingRow
    {
        $finding = array_find($this->findings->findings, static fn (Finding $f): bool => $f->id()->toRfc4122() === $findingId);

        return null === $finding ? null : $this->row($finding, $today);
    }

    private function row(Finding $finding, \DateTimeImmutable $today): FindingRow
    {
        $facts = $finding->facts();
        $running = array_find($this->acceptances->ofFinding($finding->id()), static fn (RiskAcceptance $a): bool => $a->isActiveOn($today));

        return new FindingRow(
            $finding->id()->toRfc4122(), $finding->teamId()->toRfc4122(), $finding->subjectType()->value, $finding->subjectId()->toRfc4122(), $finding->kind()->value,
            $facts->title, $facts->package, $facts->installed, $facts->fixed, $facts->severity, $facts->cvss, $facts->knownExploited, $facts->epss, $facts->eolLevel,
            $facts->endOfLife, $facts->latest, $facts->aliases, $finding->status()->value, $finding->firstSeenAt(), $finding->lastSeenAt(), $finding->fixedAt(), $running?->expiresOn(),
            $finding->placement()->name, $facts->suggested, $finding->placement()->projectId?->toRfc4122(), $finding->placement()->environmentId?->toRfc4122(),
        );
    }
}
