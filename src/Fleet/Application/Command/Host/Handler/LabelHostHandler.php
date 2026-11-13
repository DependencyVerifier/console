<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Host\Handler;

use Fleet\Application\Command\Host\LabelHost;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostLabel;
use Fleet\Domain\Repository\HostRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class LabelHostHandler implements CommandHandler
{
    public function __construct(private FleetAccess $access, private HostRepository $hosts)
    {
    }

    public function __invoke(LabelHost $command): void
    {
        $host = (Uuid::isValid($command->hostId) ? $this->hosts->find(HostId::fromString($command->hostId)) : null) ?? throw ResourceNotFound::of('host');
        $this->access->assert(Permission::HostEdit, $host->teamId()->toRfc4122());
        $host->relabel(new HostLabel($command->displayName, $command->addresses));
    }
}
