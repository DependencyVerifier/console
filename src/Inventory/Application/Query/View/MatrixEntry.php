<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

/**
 * What the read model gives for the matrix: one component version on one application.
 */
final readonly class MatrixEntry
{
    public function __construct(
        public string $kind,
        public string $ecosystem,
        public string $component,
        public string $version,
        public string $appId,
        public string $application,
        public string $hostId,
        public string $host,
    ) {
    }
}
