<?php

declare(strict_types=1);

namespace Exposure\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * Where the subject of a finding is, copied at each computation for the dashboards and the lists: its name, the
 * environment and the project of an application attached to one (none for a host or an application not attached), the
 * host it runs on (a host: itself), and whether it is in production (an application in a production environment, a
 * host carrying one).
 */
final readonly class SubjectPlacement
{
    public function __construct(
        public string $name,
        public ?Uuid $environmentId = null,
        public ?Uuid $projectId = null,
        public ?Uuid $hostId = null,
        public ?string $hostName = null,
        public bool $production = false,
    ) {
    }
}
