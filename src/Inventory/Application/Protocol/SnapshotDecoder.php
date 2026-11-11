<?php

declare(strict_types=1);

namespace Inventory\Application\Protocol;

use Inventory\Domain\Model\Digest;
use Inventory\Domain\Model\DigestMismatch;
use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\MediaType;
use Inventory\Domain\Model\SnapshotTooLarge;

use function Symfony\Component\String\b;

/**
 * Protocol §8.3: a snapshot arrives gzip-compressed. It is inflated in a stream, stopped beyond 200 MB (a gzip bomb
 * never fills the memory), its SHA-256 must be its digest, and its format is checked when it is small enough to be
 * parsed here (16 MiB decompressed, far above a usual SBOM); larger ones are checked by the processing.
 */
final class SnapshotDecoder
{
    public const int MAX_COMPRESSED = 20971520;
    public const int MAX_DECOMPRESSED = 209715200;
    public const int MAX_PARSED = 16777216;
    private const int CHUNK = 65536;

    /**
     * @return int the decompressed size
     */
    public static function check(string $gzip, Digest $digest, MediaType $mediaType): int
    {
        if (b($gzip)->length() > self::MAX_COMPRESSED) {
            throw new SnapshotTooLarge('More than 20 MB compressed.');
        }

        $inflate = @inflate_init(\ZLIB_ENCODING_GZIP);
        $hash = hash_init('sha256');
        $size = 0;
        $document = '';
        $length = b($gzip)->length();
        for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
            $last = $offset + self::CHUNK >= $length;
            $chunk = false === $inflate ? false : @inflate_add($inflate, b($gzip)->slice($offset, self::CHUNK)->toString(), $last ? \ZLIB_FINISH : \ZLIB_SYNC_FLUSH);
            if (false === $chunk) {
                throw new InvalidInventory('The snapshot is not gzip.');
            }

            $size += b($chunk)->length();
            if ($size > self::MAX_DECOMPRESSED) {
                throw new SnapshotTooLarge('More than 200 MB decompressed.');
            }

            hash_update($hash, $chunk);
            if ($size <= self::MAX_PARSED) {
                $document .= $chunk;
            }
        }

        if (false === $inflate || 0 === $length || \ZLIB_STREAM_END !== inflate_get_status($inflate)) {
            throw new InvalidInventory('The snapshot is not a complete gzip stream.');
        }

        if ('sha256:'.hash_final($hash) !== $digest->value) {
            throw new DigestMismatch();
        }

        if ($size <= self::MAX_PARSED) {
            self::checkFormat($document, $mediaType);
        }

        return $size;
    }

    /**
     * The document of a snapshot, decoded (for the processing).
     *
     * @return array<string, mixed>
     */
    public static function document(string $gzip, MediaType $mediaType): array
    {
        $json = @gzdecode($gzip, self::MAX_DECOMPRESSED);
        if (false === $json) {
            throw new InvalidInventory('The snapshot cannot be read.');
        }

        return self::checkFormat($json, $mediaType);
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkFormat(string $json, MediaType $mediaType): array
    {
        try {
            $document = json_decode($json, true, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new InvalidInventory('The snapshot is not JSON.');
        }

        $valid = \is_array($document) && match ($mediaType) {
            MediaType::CycloneDx => 'CycloneDX' === ($document['bomFormat'] ?? null) && '1.6' === ($document['specVersion'] ?? null)
                && \is_array($document['components'] ?? []),
            MediaType::HostFacts => 1 === ($document['format_version'] ?? null) && \is_string($document['hostname'] ?? null),
        };
        if (!$valid) {
            throw new InvalidInventory(\sprintf('The snapshot is not a %s document.', MediaType::CycloneDx === $mediaType ? 'CycloneDX 1.6' : 'host facts'));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
