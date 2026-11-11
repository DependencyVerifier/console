<?php

declare(strict_types=1);

namespace Inventory\Domain\Model;

use function Symfony\Component\String\u;

/**
 * One component of an SBOM (protocol §8.5), as the matching will read it: its purl, and what the agent said of it
 * (direct or transitive dependency, file it came from, package manager).
 */
final readonly class Package
{
    private const int MAX_PROPERTY = 255;

    public ?string $dependency;
    public ?string $source;
    public ?string $manager;

    public function __construct(public Purl $purl, ?string $dependency = null, ?string $source = null, ?string $manager = null)
    {
        $this->dependency = \in_array($dependency, ['direct', 'transitive'], true) ? $dependency : null;
        $this->source = self::bounded($source);
        $this->manager = self::bounded($manager);
    }

    private static function bounded(?string $value): ?string
    {
        return null === $value || '' === $value ? null : u($value)->truncate(self::MAX_PROPERTY)->toString();
    }
}
