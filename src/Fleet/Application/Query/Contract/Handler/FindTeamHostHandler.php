<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Contract\Handler;

use Fleet\Domain\Model\HostId;
use Fleet\Domain\Repository\HostRepository;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Fleet\FindTeamHost;
use Symfony\Component\Uid\Uuid;

final readonly class FindTeamHostHandler implements QueryHandler
{
    public function __construct(private HostRepository $hosts)
    {
    }

    public function __invoke(FindTeamHost $query): ?string
    {
        $host = Uuid::isValid($query->hostId) ? $this->hosts->find(HostId::fromString($query->hostId)) : null;

        return null !== $host && $host->teamId()->toRfc4122() === $query->teamId ? $host->facts()->hostname : null;
    }
}
