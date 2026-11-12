<?php

declare(strict_types=1);

namespace Tests\Exposure\Support;

use Exposure\Application\Query\FindingReadModel;
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
        );
    }
}
