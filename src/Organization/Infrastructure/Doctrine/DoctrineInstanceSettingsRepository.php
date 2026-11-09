<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Doctrine;

use Organization\Domain\Model\InstanceSettings;
use Organization\Domain\Repository\InstanceSettingsRepository;

final readonly class DoctrineInstanceSettingsRepository implements InstanceSettingsRepository
{
    public function __construct(private OrganizationManager $manager)
    {
    }

    public function current(): InstanceSettings
    {
        $settings = $this->manager->get()->find(InstanceSettings::class, InstanceSettings::ID);
        if (null === $settings) {
            $settings = InstanceSettings::initial();
            $this->manager->get()->persist($settings);
        }

        return $settings;
    }
}
