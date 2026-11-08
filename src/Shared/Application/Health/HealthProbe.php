<?php

declare(strict_types=1);

namespace Shared\Application\Health;

use Shared\Domain\Health\ProbeResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A dependency checked by `/_healthcheck` (database, queues…). Implementations live in Infrastructure.
 */
#[AutoconfigureTag]
interface HealthProbe
{
    public function check(): ProbeResult;
}
