<?php

declare(strict_types=1);

namespace Tests\Exposure\Support;

use Exposure\Domain\Model\Finding;
use Exposure\Domain\Repository\FindingRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryFindings implements FindingRepository
{
    /** @var list<Finding> */
    public array $findings = [];

    public function ofTeam(Uuid $teamId): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->teamId()->equals($teamId)));
    }

    public function find(Uuid $findingId): ?Finding
    {
        return array_find($this->findings, static fn (Finding $f): bool => $f->id()->equals($findingId));
    }

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->findings = array_values(array_filter($this->findings, static fn (Finding $f): bool => !$f->teamId()->equals($teamId)));
    }
}
