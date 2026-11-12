<?php

declare(strict_types=1);

namespace Tests\Exposure\Support;

use Exposure\Domain\Model\TeamExposure;
use Exposure\Domain\Repository\TeamExposureRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryTeamExposures implements TeamExposureRepository
{
    /** @var array<string, TeamExposure> */
    public array $exposures = [];

    public function find(Uuid $teamId): ?TeamExposure
    {
        return $this->exposures[$teamId->toRfc4122()] ?? null;
    }

    public function add(TeamExposure $exposure): void
    {
        $this->exposures[$exposure->teamId()->toRfc4122()] = $exposure;
    }

    public function deleteTeam(Uuid $teamId): void
    {
        unset($this->exposures[$teamId->toRfc4122()]);
    }
}
