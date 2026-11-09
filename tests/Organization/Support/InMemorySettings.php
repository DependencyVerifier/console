<?php

declare(strict_types=1);

namespace Tests\Organization\Support;

use Organization\Domain\Model\InstanceSettings;
use Organization\Domain\Repository\InstanceSettingsRepository;

final class InMemorySettings implements InstanceSettingsRepository
{
    public InstanceSettings $settings;

    public function __construct()
    {
        $this->settings = InstanceSettings::initial();
    }

    public function current(): InstanceSettings
    {
        return $this->settings;
    }
}
