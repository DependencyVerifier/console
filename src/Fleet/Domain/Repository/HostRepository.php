<?php

declare(strict_types=1);

namespace Fleet\Domain\Repository;

use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostIdentity;
use Symfony\Component\Uid\Uuid;

interface HostRepository
{
    public function find(HostId $id): ?Host;

    public function findByIdentity(Uuid $teamId, HostIdentity $identity): ?Host;

    public function add(Host $host): void;

    public function remove(Host $host): void;

    /**
     * Every row of the team (DeleteTeam cascade).
     */
    public function deleteOfTeam(Uuid $teamId): void;
}
