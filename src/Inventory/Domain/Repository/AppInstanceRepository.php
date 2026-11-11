<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\AppInstance;
use Symfony\Component\Uid\Uuid;

interface AppInstanceRepository
{
    public function find(Uuid $id): ?AppInstance;

    /**
     * @return list<AppInstance> every application ever found on the host, gone ones included
     */
    public function ofHost(Uuid $hostId): array;

    public function add(AppInstance $app): void;

    public function countInEnvironment(Uuid $environmentId): int;

    public function deleteOfTeam(Uuid $teamId): void;
}
