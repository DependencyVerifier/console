<?php

declare(strict_types=1);

namespace Inventory\Domain\Repository;

use Inventory\Domain\Model\ContainerImage;
use Symfony\Component\Uid\Uuid;

interface ContainerImageRepository
{
    public function find(Uuid $id): ?ContainerImage;

    public function findByImageId(Uuid $teamId, string $imageId): ?ContainerImage;

    public function add(ContainerImage $image): void;

    public function deleteOfTeam(Uuid $teamId): void;
}
