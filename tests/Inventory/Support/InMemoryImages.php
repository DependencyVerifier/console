<?php

declare(strict_types=1);

namespace Tests\Inventory\Support;

use Inventory\Domain\Model\ContainerImage;
use Inventory\Domain\Repository\ContainerImageRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryImages implements ContainerImageRepository
{
    /** @var array<string, ContainerImage> */
    public array $images = [];

    public function find(Uuid $id): ?ContainerImage
    {
        return $this->images[$id->toRfc4122()] ?? null;
    }

    public function findByImageId(Uuid $teamId, string $imageId): ?ContainerImage
    {
        return array_find($this->images, static fn (ContainerImage $i): bool => $i->teamId()->equals($teamId) && $i->imageId() === $imageId);
    }

    public function add(ContainerImage $image): void
    {
        $this->images[$image->id()->toRfc4122()] = $image;
    }

    public function deleteOfTeam(Uuid $teamId): void
    {
        $this->images = array_filter($this->images, static fn (ContainerImage $i): bool => !$i->teamId()->equals($teamId));
    }
}
