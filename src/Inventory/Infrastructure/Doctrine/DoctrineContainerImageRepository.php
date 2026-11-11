<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Doctrine;

use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Repository\ContainerImageRepository;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineContainerImageRepository implements ContainerImageRepository
{
    public function __construct(private InventoryManager $manager)
    {
    }

    public function find(Uuid $id): ?ContainerImage
    {
        return $this->manager->get()->find(ContainerImage::class, $id);
    }

    public function findByImageId(Uuid $teamId, string $imageId): ?ContainerImage
    {
        return $this->manager->get()->getRepository(ContainerImage::class)->findOneBy(['teamId' => $teamId, 'imageId' => $imageId]);
    }

    public function add(ContainerImage $image): void
    {
        $this->manager->get()->persist($image);
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->manager->get()->createQueryBuilder()->delete(ContainerImage::class, 'i')->where('i.teamId = :team')
            ->setParameter('team', $teamId, 'uuid')->getQuery()->execute();
    }
}
