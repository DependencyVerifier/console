<?php

declare(strict_types=1);

namespace Fleet\Application\Query\Team\Handler;

use Fleet\Application\Query\Team\GetHostLabel;
use Fleet\Application\Query\View\HostLabelView;
use Fleet\Application\Security\FleetAccess;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Repository\HostRepository;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;

final readonly class GetHostLabelHandler implements QueryHandler
{
    public function __construct(private FleetAccess $access, private HostRepository $hosts)
    {
    }

    public function __invoke(GetHostLabel $query): HostLabelView
    {
        $host = Uuid::isValid($query->hostId) ? $this->hosts->find(HostId::fromString($query->hostId)) : null;
        if (null === $host || $host->teamId()->toRfc4122() !== $query->teamId) {
            throw ResourceNotFound::of('host');
        }

        $this->access->assert(Permission::HostEdit, $query->teamId);
        $label = $host->label();

        return new HostLabelView($host->id()->toString(), $host->facts()->hostname, $label->displayName, $label->addresses);
    }
}
