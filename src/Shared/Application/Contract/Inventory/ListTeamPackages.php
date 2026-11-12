<?php

declare(strict_types=1);

namespace Shared\Application\Contract\Inventory;

use Shared\Application\Bus\Query;

/**
 * Published query of Inventory: the packages a team runs now (the OS packages of its hosts, the components of its
 * applications still found and of its images), each package URL once, direct when one of its SBOMs says so.
 *
 * @implements Query<list<array{purl: string, direct: bool}>>
 */
final readonly class ListTeamPackages implements Query
{
    public function __construct(public string $teamId)
    {
    }
}
