<?php

declare(strict_types=1);

namespace Fleet\Infrastructure\Doctrine;

use Fleet\Domain\Model\Host;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\HostIdentity;
use Fleet\Domain\Repository\HostRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineHostRepository implements HostRepository
{
    public function __construct(private FleetManager $manager)
    {
    }

    public function find(HostId $id): ?Host
    {
        return $this->manager->get()->find(Host::class, $id->value);
    }

    public function findByIdentity(Uuid $teamId, HostIdentity $identity): ?Host
    {
        return $this->manager->get()->getRepository(Host::class)->findOneBy([
            'teamId' => $teamId, 'identity.kind' => $identity->kind, 'identity.value' => $identity->value,
        ]);
    }

    public function add(Host $host): void
    {
        $this->manager->get()->persist($host);
    }

    public function remove(Host $host): void
    {
        $this->manager->get()->remove($host);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(Host::class, 'x')->where('x.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
