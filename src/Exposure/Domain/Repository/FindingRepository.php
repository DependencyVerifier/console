<?php

declare(strict_types=1);

namespace Exposure\Domain\Repository;

use Exposure\Domain\Model\Finding;
use Symfony\Component\Uid\Uuid;

interface FindingRepository
{
    /**
     * @return list<Finding> open and fixed
     */
    public function ofTeam(Uuid $teamId): array;

    public function find(Uuid $findingId): ?Finding;

    public function add(Finding $finding): void;

    public function deleteTeam(Uuid $teamId): void;
}
