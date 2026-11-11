<?php

declare(strict_types=1);

namespace Tests\Fleet\Support;

use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Repository\HostRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryHosts implements HostRepository
{
    /** @var array<string, Host> */
    public array $hosts = [];

    public function find(HostId $id): ?Host
    {
        return $this->hosts[$id->toString()] ?? null;
    }

    public function findByIdentity(Uuid $teamId, HostIdentity $identity): ?Host
    {
        foreach ($this->hosts as $host) {
            if ($host->teamId()->equals($teamId) && $host->identity() == $identity) {
                return $host;
            }
        }

        return null;
    }

    public function add(Host $host): void
    {
        $this->hosts[$host->id()->toString()] = $host;
    }

    public function remove(Host $host): void
    {
        unset($this->hosts[$host->id()->toString()]);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->hosts = array_filter($this->hosts, static fn (Host $x): bool => !$x->teamId()->equals($teamId));
    }
}
