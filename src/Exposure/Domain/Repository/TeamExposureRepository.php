<?php

declare(strict_types=1);

namespace Exposure\Domain\Repository;

use Exposure\Domain\Model\TeamExposure;
use Symfony\Component\Uid\Uuid;

interface TeamExposureRepository
{
    public function find(Uuid $teamId): ?TeamExposure;

    public function add(TeamExposure $exposure): void;

    public function deleteTeam(Uuid $teamId): void;
}
