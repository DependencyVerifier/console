<?php

declare(strict_types=1);

namespace Organization\Application\Command\Instance\Handler;

use Organization\Application\Command\Instance\UpdateDefaultQuotas;
use Organization\Application\Command\QuotaInput;
use Organization\Application\Security\OrganizationAccess;
use Organization\Domain\Repository\InstanceSettingsRepository;
use Shared\Application\Bus\CommandHandler;
use Shared\Domain\Authorization\Permission;

final readonly class UpdateDefaultQuotasHandler implements CommandHandler
{
    public function __construct(private OrganizationAccess $access, private InstanceSettingsRepository $settings)
    {
    }

    public function __invoke(UpdateDefaultQuotas $command): void
    {
        $this->access->assertInstance(Permission::InstanceDefaultQuotasManage);
        $this->settings->current()->changeDefaultQuotas(QuotaInput::teamQuotas($command->quotas));
    }
}
