<?php

declare(strict_types=1);

namespace Inventory\Application\Query\View;

/**
 * A package worth naming in the header of an application (a framework), with its installed version.
 */
final readonly class KeyPackage
{
    public function __construct(public string $label, public ?string $version)
    {
    }
}
