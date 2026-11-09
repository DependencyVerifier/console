<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Domain\Authorization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;

final class PermissionTest extends TestCase
{
    /**
     * @return iterable<string, array{Permission, PermissionScope}>
     */
    public static function provideScopes(): iterable
    {
        $instance = ['account.login', 'instance.settings', 'instance.users.manage', 'instance.audit.view', 'instance.super_admin.manage', 'instance.agents.view', 'instance.default_quotas.manage', 'team.create', 'team.delete', 'team.quotas.manage'];
        $team = ['team.edit', 'team.members.manage', 'team.audit.view', 'team.channels.manage', 'host.enroll', 'host.approve', 'host.revoke', 'host.delete', 'host.order', 'project.create'];

        foreach (Permission::cases() as $permission) {
            $scope = match (true) {
                \in_array($permission->value, $instance, true) => PermissionScope::Instance,
                \in_array($permission->value, $team, true) => PermissionScope::Team,
                default => PermissionScope::Project,
            };

            yield $permission->value => [$permission, $scope];
        }
    }

    #[Test]
    #[DataProvider('provideScopes')]
    public function it_should_carry_the_scope_of_the_catalogue(Permission $permission, PermissionScope $scope): void
    {
        self::assertSame($scope, $permission->scope());
    }
}
