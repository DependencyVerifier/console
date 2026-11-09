<?php

declare(strict_types=1);

namespace Organization\Application\Query\Instance\Handler;

use Organization\Application\Query\Instance\GetDefaultQuotas;
use Organization\Application\Security\ActorPermissions;
use Organization\Domain\Model\Quota;
use Organization\Domain\Repository\InstanceSettingsRepository;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Bus\QueryHandler;
use Shared\Domain\Authorization\Permission;

final readonly class GetDefaultQuotasHandler implements QueryHandler
{
    public function __construct(private ActorPermissions $permissions, private InstanceSettingsRepository $settings)
    {
    }

    /**
     * @return array<string, ?int>
     */
    public function __invoke(GetDefaultQuotas $query): array
    {
        if (!$this->permissions->current()->isSuperAdmin()) {
            throw PermissionDenied::for(Permission::InstanceDefaultQuotasManage->value);
        }

        return array_map(static fn (Quota $quota): ?int => $quota->toStorage(), $this->settings->current()->defaultQuotas()->all());
    }
}
