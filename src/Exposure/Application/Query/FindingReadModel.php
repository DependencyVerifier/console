<?php

declare(strict_types=1);

namespace Exposure\Application\Query;

use Exposure\Application\Query\View\ExposureSummary;
use Exposure\Application\Query\View\FindingRow;
use Shared\Application\Query\Page;
use Shared\Application\Query\PageRequest;

/**
 * Read side of Exposure (DBAL). It filters by what it is given; the handlers decide what the actor may see.
 */
interface FindingReadModel
{
    /**
     * The findings of a subject: open first (the most severe first), then fixed.
     *
     * @return Page<FindingRow>
     */
    public function ofSubject(string $teamId, string $subjectType, string $subjectId, \DateTimeImmutable $today, PageRequest $page): Page;

    public function find(string $findingId, \DateTimeImmutable $today): ?FindingRow;

    /**
     * @return Page<FindingRow>
     */
    public function list(ExposureFilter $filter, FindingCriteria $criteria, \DateTimeImmutable $today, PageRequest $page): Page;

    public function summary(ExposureFilter $filter, \DateTimeImmutable $today): ExposureSummary;

    /**
     * The most urgent open findings (not accepted) the filter lets through: exploited, then critical and high
     * vulnerabilities, then ends of life reached or coming; release lag never.
     *
     * @return list<FindingRow>
     */
    public function urgent(ExposureFilter $filter, \DateTimeImmutable $today, int $limit): array;

    /**
     * The open figures (not accepted) of each project the filter lets through: exploited, critical and high
     * vulnerabilities, ends of life soon, imminent or reached.
     *
     * @return array<string, array{knownExploited: int, critical: int, high: int, endsOfLife: int}> project id => figures
     */
    public function projectFigures(ExposureFilter $filter, \DateTimeImmutable $today): array;

    /**
     * The hosts of the findings the filter lets through (a host and the hosts of applications), by name.
     *
     * @return array<string, string> host id => name
     */
    public function hosts(ExposureFilter $filter): array;
}
