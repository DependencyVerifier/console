<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

/**
 * A snapshot announced by a manifest: `{digest, size, media_type}`.
 */
final readonly class SnapshotRef
{
    public const int MAX_SIZE = 209715200;

    public function __construct(public Digest $digest, public int $size, public MediaType $mediaType)
    {
        if ($size < 0 || $size > self::MAX_SIZE) {
            throw new InvalidInventory('Snapshot size out of bounds.');
        }
    }

    /**
     * @return array{digest: string, size: int, media_type: string}
     */
    public function toArray(): array
    {
        return ['digest' => $this->digest->value, 'size' => $this->size, 'media_type' => $this->mediaType->value];
    }

    /**
     * @param array{digest: string, size: int, media_type: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Digest::of($data['digest']), $data['size'], MediaType::from($data['media_type']));
    }
}
