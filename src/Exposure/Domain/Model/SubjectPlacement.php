<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * Where the subject of a finding is, copied at each computation for the dashboards and the lists: its name, the
 * environment and the project of an application attached to one (none for a host or an application not attached).
 */
final readonly class SubjectPlacement
{
    public function __construct(public string $name, public ?Uuid $environmentId = null, public ?Uuid $projectId = null)
    {
    }
}
