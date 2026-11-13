<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Inventory\Domain\Model\HostLabel;
use Inventory\Domain\Repository\HostLabelRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineHostLabelRepository implements HostLabelRepository
{
    public function __construct(private InventoryManager $manager)
    {
    }

    public function find(Uuid $hostId): ?HostLabel
    {
        return $this->manager->get()->find(HostLabel::class, $hostId);
    }

    public function add(HostLabel $label): void
    {
        $this->manager->get()->persist($label);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(HostLabel::class, 'l')->where('l.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
