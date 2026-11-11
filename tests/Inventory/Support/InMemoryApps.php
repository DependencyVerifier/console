<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\AppInstance;
use Inventory\Domain\Repository\AppInstanceRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryApps implements AppInstanceRepository
{
    /** @var array<string, AppInstance> */
    public array $apps = [];

    public function find(Uuid $id): ?AppInstance
    {
        return $this->apps[$id->toRfc4122()] ?? null;
    }

    public function ofHost(Uuid $hostId): array
    {
        return array_values(array_filter($this->apps, static fn (AppInstance $a): bool => $a->hostId()->equals($hostId)));
    }

    public function add(AppInstance $app): void
    {
        $this->apps[$app->id()->toRfc4122()] = $app;
    }

    public function countInEnvironment(Uuid $environmentId): int
    {
        return \count(array_filter($this->apps, static fn (AppInstance $a): bool => true === $a->environmentId()?->equals($environmentId)));
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->apps = array_filter($this->apps, static fn (AppInstance $a): bool => !$a->teamId()->equals($teamId));
    }
}
