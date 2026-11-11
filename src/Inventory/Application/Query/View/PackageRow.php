<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

final readonly class PackageRow
{
    public function __construct(
        public string $purl,
        public string $type,
        public ?string $namespace,
        public string $name,
        public ?string $version,
        public ?string $dependency,
        public ?string $manager,
    ) {
    }

    public function fullName(): string
    {
        return null === $this->namespace ? $this->name : $this->namespace.'/'.$this->name;
    }
}
