<?php

declare(strict_types=1);

namespace Inventory\Application\Protocol;

use Inventory\Domain\Model\InvalidInventory;
use Inventory\Domain\Model\Package;
use Inventory\Domain\Model\Purl;

/**
 * The packages of a CycloneDX 1.6 SBOM (protocol §8.5): every component with a valid purl, nested ones included, with
 * the `depverify:*` properties. A component without purl (or with an invalid one) is skipped: the matching could not
 * use it anyway.
 */
final class CycloneDxReader
{
    public const int MAX_COMPONENTS = 100000;

    /**
     * @param array<string, mixed> $sbom
     *
     * @return array{packages: list<Package>, skipped: int}
     */
    public static function packages(array $sbom): array
    {
        $packages = [];
        $skipped = 0;
        // A queue read by index (nested components appended at its end): linear, whatever the size.
        $queue = \is_array($sbom['components'] ?? null) ? array_values($sbom['components']) : [];
        for ($next = 0; $next < \count($queue) && $next < self::MAX_COMPONENTS; ++$next) {
            $component = $queue[$next];
            if (!\is_array($component)) {
                ++$skipped;
                continue;
            }

            if (\is_array($component['components'] ?? null)) {
                array_push($queue, ...array_values($component['components']));
            }

            $package = self::package($component);
            null === $package ? ++$skipped : $packages[] = $package;
        }

        return ['packages' => $packages, 'skipped' => $skipped + \count($queue) - $next];
    }

    /**
     * @param array<mixed> $component
     */
    private static function package(array $component): ?Package
    {
        if (!\is_string($component['purl'] ?? null)) {
            return null;
        }

        try {
            $purl = Purl::parse($component['purl']);
        } catch (InvalidInventory) {
            return null;
        }

        $properties = [];
        foreach (\is_array($component['properties'] ?? null) ? $component['properties'] : [] as $property) {
            if (\is_array($property) && \is_string($property['name'] ?? null) && \is_string($property['value'] ?? null)) {
                $properties[$property['name']] = $property['value'];
            }
        }

        return new Package($purl, $properties['depverify:dependency'] ?? null, $properties['depverify:source'] ?? null, $properties['depverify:package-manager'] ?? null);
    }
}
