<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

final readonly class ImageView
{
    /**
     * @param list<string> $tags
     * @param list<string> $repoDigests
     */
    public function __construct(
        public string $id,
        public string $teamId,
        public string $imageId,
        public array $tags,
        public array $repoDigests,
        public ?string $provenance,
        public ?string $baseImage,
        public \DateTimeImmutable $lastSeenAt,
        public string $sbomDigest,
        public int $packages,
    ) {
    }
}
