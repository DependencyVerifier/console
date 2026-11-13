<?php

declare(strict_types=1);

namespace Tests\Exposure\Support;

use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Repository\RiskAcceptanceRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryRiskAcceptances implements RiskAcceptanceRepository
{
    /** @var list<RiskAcceptance> in the order added */
    public array $acceptances = [];

    public function ofFinding(Uuid $findingId): array
    {
        return array_reverse(array_values(array_filter($this->acceptances, static fn (RiskAcceptance $a): bool => $a->findingId()->equals($findingId))));
    }

    public function add(RiskAcceptance $acceptance): void
    {
        $this->acceptances[] = $acceptance;
    }

    public function expiringBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->format('Y-m-d');
        $to = $to->format('Y-m-d');

        return array_values(array_filter($this->acceptances, static fn (RiskAcceptance $a): bool => null === $a->revokedAt() && $a->expiresOn()->format('Y-m-d') >= $from && $a->expiresOn()->format('Y-m-d') <= $to));
    }

    public function deleteTeam(Uuid $teamId): void
    {
        $this->acceptances = array_values(array_filter($this->acceptances, static fn (RiskAcceptance $a): bool => !$a->teamId()->equals($teamId)));
    }
}
