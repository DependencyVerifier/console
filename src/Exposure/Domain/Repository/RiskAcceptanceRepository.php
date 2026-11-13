<?php

declare(strict_types=1);

namespace Exposure\Domain\Repository;

use Exposure\Domain\Model\RiskAcceptance;
use Symfony\Component\Uid\Uuid;

interface RiskAcceptanceRepository
{
    /**
     * @return list<RiskAcceptance> the history of a finding, the latest first
     */
    public function ofFinding(Uuid $findingId): array;

    public function add(RiskAcceptance $acceptance): void;

    /**
     * Not revoked, expiring between the two days (included).
     *
     * @return list<RiskAcceptance>
     */
    public function expiringBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function deleteTeam(Uuid $teamId): void;
}
