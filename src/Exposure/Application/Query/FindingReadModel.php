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
}
