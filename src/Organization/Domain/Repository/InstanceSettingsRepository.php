<?php

declare(strict_types=1);

namespace Organization\Domain\Repository;

use Organization\Domain\Model\InstanceSettings;

interface InstanceSettingsRepository
{
    /**
     * The singleton, created with the default values on first use.
     */
    public function current(): InstanceSettings;
}
