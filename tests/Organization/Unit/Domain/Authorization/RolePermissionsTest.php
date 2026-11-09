<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Domain\Authorization;

use Organization\Domain\Authorization\RolePermissions;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Shared\Domain\Authorization\PermissionScope;

/**
 * One case per cell of docs/cadrage.md §7bis (generated from the table): ✓ default, ○ grantable, empty impossible.
 */
final class RolePermissionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function matrix(): iterable
    {
        yield 'team.edit TA' => ['team.edit', 'TA', 'default'];
        yield 'team.edit TM' => ['team.edit', 'TM', 'none'];
        yield 'team.edit TV' => ['team.edit', 'TV', 'none'];
        yield 'team.edit TG' => ['team.edit', 'TG', 'none'];
        yield 'team.edit PA' => ['team.edit', 'PA', 'none'];
        yield 'team.edit PM' => ['team.edit', 'PM', 'none'];
        yield 'team.edit PV' => ['team.edit', 'PV', 'none'];
        yield 'team.members.manage TA' => ['team.members.manage', 'TA', 'default'];
        yield 'team.members.manage TM' => ['team.members.manage', 'TM', 'none'];
        yield 'team.members.manage TV' => ['team.members.manage', 'TV', 'none'];
        yield 'team.members.manage TG' => ['team.members.manage', 'TG', 'none'];
        yield 'team.members.manage PA' => ['team.members.manage', 'PA', 'none'];
        yield 'team.members.manage PM' => ['team.members.manage', 'PM', 'none'];
        yield 'team.members.manage PV' => ['team.members.manage', 'PV', 'none'];
        yield 'team.audit.view TA' => ['team.audit.view', 'TA', 'default'];
        yield 'team.audit.view TM' => ['team.audit.view', 'TM', 'grantable'];
        yield 'team.audit.view TV' => ['team.audit.view', 'TV', 'grantable'];
        yield 'team.audit.view TG' => ['team.audit.view', 'TG', 'none'];
        yield 'team.audit.view PA' => ['team.audit.view', 'PA', 'none'];
        yield 'team.audit.view PM' => ['team.audit.view', 'PM', 'none'];
        yield 'team.audit.view PV' => ['team.audit.view', 'PV', 'none'];
        yield 'team.channels.manage TA' => ['team.channels.manage', 'TA', 'default'];
        yield 'team.channels.manage TM' => ['team.channels.manage', 'TM', 'grantable'];
        yield 'team.channels.manage TV' => ['team.channels.manage', 'TV', 'none'];
        yield 'team.channels.manage TG' => ['team.channels.manage', 'TG', 'none'];
        yield 'team.channels.manage PA' => ['team.channels.manage', 'PA', 'none'];
        yield 'team.channels.manage PM' => ['team.channels.manage', 'PM', 'none'];
        yield 'team.channels.manage PV' => ['team.channels.manage', 'PV', 'none'];
        yield 'host.enroll TA' => ['host.enroll', 'TA', 'default'];
        yield 'host.enroll TM' => ['host.enroll', 'TM', 'grantable'];
        yield 'host.enroll TV' => ['host.enroll', 'TV', 'none'];
        yield 'host.enroll TG' => ['host.enroll', 'TG', 'none'];
        yield 'host.enroll PA' => ['host.enroll', 'PA', 'none'];
        yield 'host.enroll PM' => ['host.enroll', 'PM', 'none'];
        yield 'host.enroll PV' => ['host.enroll', 'PV', 'none'];
        yield 'host.approve TA' => ['host.approve', 'TA', 'default'];
        yield 'host.approve TM' => ['host.approve', 'TM', 'grantable'];
        yield 'host.approve TV' => ['host.approve', 'TV', 'none'];
        yield 'host.approve TG' => ['host.approve', 'TG', 'none'];
        yield 'host.approve PA' => ['host.approve', 'PA', 'none'];
        yield 'host.approve PM' => ['host.approve', 'PM', 'none'];
        yield 'host.approve PV' => ['host.approve', 'PV', 'none'];
        yield 'host.revoke TA' => ['host.revoke', 'TA', 'default'];
        yield 'host.revoke TM' => ['host.revoke', 'TM', 'grantable'];
        yield 'host.revoke TV' => ['host.revoke', 'TV', 'none'];
        yield 'host.revoke TG' => ['host.revoke', 'TG', 'none'];
        yield 'host.revoke PA' => ['host.revoke', 'PA', 'none'];
        yield 'host.revoke PM' => ['host.revoke', 'PM', 'none'];
        yield 'host.revoke PV' => ['host.revoke', 'PV', 'none'];
        yield 'host.delete TA' => ['host.delete', 'TA', 'default'];
        yield 'host.delete TM' => ['host.delete', 'TM', 'grantable'];
        yield 'host.delete TV' => ['host.delete', 'TV', 'none'];
        yield 'host.delete TG' => ['host.delete', 'TG', 'none'];
        yield 'host.delete PA' => ['host.delete', 'PA', 'none'];
        yield 'host.delete PM' => ['host.delete', 'PM', 'none'];
        yield 'host.delete PV' => ['host.delete', 'PV', 'none'];
        yield 'host.order TA' => ['host.order', 'TA', 'default'];
        yield 'host.order TM' => ['host.order', 'TM', 'grantable'];
        yield 'host.order TV' => ['host.order', 'TV', 'none'];
        yield 'host.order TG' => ['host.order', 'TG', 'none'];
        yield 'host.order PA' => ['host.order', 'PA', 'none'];
        yield 'host.order PM' => ['host.order', 'PM', 'none'];
        yield 'host.order PV' => ['host.order', 'PV', 'none'];
        yield 'host.view TA' => ['host.view', 'TA', 'default'];
        yield 'host.view TM' => ['host.view', 'TM', 'default'];
        yield 'host.view TV' => ['host.view', 'TV', 'default'];
        yield 'host.view TG' => ['host.view', 'TG', 'none'];
        yield 'host.view PA' => ['host.view', 'PA', 'default'];
        yield 'host.view PM' => ['host.view', 'PM', 'default'];
        yield 'host.view PV' => ['host.view', 'PV', 'default'];
        yield 'project.create TA' => ['project.create', 'TA', 'default'];
        yield 'project.create TM' => ['project.create', 'TM', 'grantable'];
        yield 'project.create TV' => ['project.create', 'TV', 'none'];
        yield 'project.create TG' => ['project.create', 'TG', 'grantable'];
        yield 'project.create PA' => ['project.create', 'PA', 'none'];
        yield 'project.create PM' => ['project.create', 'PM', 'none'];
        yield 'project.create PV' => ['project.create', 'PV', 'none'];
        yield 'project.edit TA' => ['project.edit', 'TA', 'default'];
        yield 'project.edit TM' => ['project.edit', 'TM', 'grantable'];
        yield 'project.edit TV' => ['project.edit', 'TV', 'none'];
        yield 'project.edit TG' => ['project.edit', 'TG', 'none'];
        yield 'project.edit PA' => ['project.edit', 'PA', 'default'];
        yield 'project.edit PM' => ['project.edit', 'PM', 'grantable'];
        yield 'project.edit PV' => ['project.edit', 'PV', 'none'];
        yield 'project.delete TA' => ['project.delete', 'TA', 'default'];
        yield 'project.delete TM' => ['project.delete', 'TM', 'grantable'];
        yield 'project.delete TV' => ['project.delete', 'TV', 'none'];
        yield 'project.delete TG' => ['project.delete', 'TG', 'none'];
        yield 'project.delete PA' => ['project.delete', 'PA', 'grantable'];
        yield 'project.delete PM' => ['project.delete', 'PM', 'none'];
        yield 'project.delete PV' => ['project.delete', 'PV', 'none'];
        yield 'project.members.manage TA' => ['project.members.manage', 'TA', 'default'];
        yield 'project.members.manage TM' => ['project.members.manage', 'TM', 'grantable'];
        yield 'project.members.manage TV' => ['project.members.manage', 'TV', 'none'];
        yield 'project.members.manage TG' => ['project.members.manage', 'TG', 'none'];
        yield 'project.members.manage PA' => ['project.members.manage', 'PA', 'default'];
        yield 'project.members.manage PM' => ['project.members.manage', 'PM', 'grantable'];
        yield 'project.members.manage PV' => ['project.members.manage', 'PV', 'none'];
        yield 'project.environments.manage TA' => ['project.environments.manage', 'TA', 'default'];
        yield 'project.environments.manage TM' => ['project.environments.manage', 'TM', 'default'];
        yield 'project.environments.manage TV' => ['project.environments.manage', 'TV', 'none'];
        yield 'project.environments.manage TG' => ['project.environments.manage', 'TG', 'none'];
        yield 'project.environments.manage PA' => ['project.environments.manage', 'PA', 'default'];
        yield 'project.environments.manage PM' => ['project.environments.manage', 'PM', 'grantable'];
        yield 'project.environments.manage PV' => ['project.environments.manage', 'PV', 'none'];
        yield 'project.apps.assign TA' => ['project.apps.assign', 'TA', 'default'];
        yield 'project.apps.assign TM' => ['project.apps.assign', 'TM', 'default'];
        yield 'project.apps.assign TV' => ['project.apps.assign', 'TV', 'none'];
        yield 'project.apps.assign TG' => ['project.apps.assign', 'TG', 'none'];
        yield 'project.apps.assign PA' => ['project.apps.assign', 'PA', 'default'];
        yield 'project.apps.assign PM' => ['project.apps.assign', 'PM', 'grantable'];
        yield 'project.apps.assign PV' => ['project.apps.assign', 'PV', 'none'];
        yield 'project.scan_now TA' => ['project.scan_now', 'TA', 'default'];
        yield 'project.scan_now TM' => ['project.scan_now', 'TM', 'default'];
        yield 'project.scan_now TV' => ['project.scan_now', 'TV', 'none'];
        yield 'project.scan_now TG' => ['project.scan_now', 'TG', 'none'];
        yield 'project.scan_now PA' => ['project.scan_now', 'PA', 'default'];
        yield 'project.scan_now PM' => ['project.scan_now', 'PM', 'default'];
        yield 'project.scan_now PV' => ['project.scan_now', 'PV', 'grantable'];
        yield 'project.notifications.manage TA' => ['project.notifications.manage', 'TA', 'default'];
        yield 'project.notifications.manage TM' => ['project.notifications.manage', 'TM', 'default'];
        yield 'project.notifications.manage TV' => ['project.notifications.manage', 'TV', 'none'];
        yield 'project.notifications.manage TG' => ['project.notifications.manage', 'TG', 'none'];
        yield 'project.notifications.manage PA' => ['project.notifications.manage', 'PA', 'default'];
        yield 'project.notifications.manage PM' => ['project.notifications.manage', 'PM', 'grantable'];
        yield 'project.notifications.manage PV' => ['project.notifications.manage', 'PV', 'none'];
        yield 'project.notifications.subscribe TA' => ['project.notifications.subscribe', 'TA', 'default'];
        yield 'project.notifications.subscribe TM' => ['project.notifications.subscribe', 'TM', 'default'];
        yield 'project.notifications.subscribe TV' => ['project.notifications.subscribe', 'TV', 'default'];
        yield 'project.notifications.subscribe TG' => ['project.notifications.subscribe', 'TG', 'none'];
        yield 'project.notifications.subscribe PA' => ['project.notifications.subscribe', 'PA', 'default'];
        yield 'project.notifications.subscribe PM' => ['project.notifications.subscribe', 'PM', 'default'];
        yield 'project.notifications.subscribe PV' => ['project.notifications.subscribe', 'PV', 'default'];
        yield 'finding.view TA' => ['finding.view', 'TA', 'default'];
        yield 'finding.view TM' => ['finding.view', 'TM', 'default'];
        yield 'finding.view TV' => ['finding.view', 'TV', 'default'];
        yield 'finding.view TG' => ['finding.view', 'TG', 'none'];
        yield 'finding.view PA' => ['finding.view', 'PA', 'default'];
        yield 'finding.view PM' => ['finding.view', 'PM', 'default'];
        yield 'finding.view PV' => ['finding.view', 'PV', 'default'];
        yield 'finding.accept_risk TA' => ['finding.accept_risk', 'TA', 'default'];
        yield 'finding.accept_risk TM' => ['finding.accept_risk', 'TM', 'grantable'];
        yield 'finding.accept_risk TV' => ['finding.accept_risk', 'TV', 'none'];
        yield 'finding.accept_risk TG' => ['finding.accept_risk', 'TG', 'none'];
        yield 'finding.accept_risk PA' => ['finding.accept_risk', 'PA', 'default'];
        yield 'finding.accept_risk PM' => ['finding.accept_risk', 'PM', 'grantable'];
        yield 'finding.accept_risk PV' => ['finding.accept_risk', 'PV', 'none'];
        yield 'finding.export TA' => ['finding.export', 'TA', 'default'];
        yield 'finding.export TM' => ['finding.export', 'TM', 'default'];
        yield 'finding.export TV' => ['finding.export', 'TV', 'default'];
        yield 'finding.export TG' => ['finding.export', 'TG', 'none'];
        yield 'finding.export PA' => ['finding.export', 'PA', 'default'];
        yield 'finding.export PM' => ['finding.export', 'PM', 'default'];
        yield 'finding.export PV' => ['finding.export', 'PV', 'default'];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function it_should_match_the_published_matrix(string $permission, string $role, string $kind): void
    {
        $permission = Permission::from($permission);
        [$defaults, $grantable] = match ($role) {
            'TA' => [RolePermissions::defaultsOfTeamRole(TeamRole::Admin), RolePermissions::grantableToTeamRole(TeamRole::Admin)],
            'TM' => [RolePermissions::defaultsOfTeamRole(TeamRole::Member), RolePermissions::grantableToTeamRole(TeamRole::Member)],
            'TV' => [RolePermissions::defaultsOfTeamRole(TeamRole::Viewer), RolePermissions::grantableToTeamRole(TeamRole::Viewer)],
            'TG' => [RolePermissions::defaultsOfTeamRole(TeamRole::Guest), RolePermissions::grantableToTeamRole(TeamRole::Guest)],
            'PA' => [RolePermissions::defaultsOfProjectRole(ProjectRole::Admin), RolePermissions::grantableToProjectRole(ProjectRole::Admin)],
            'PM' => [RolePermissions::defaultsOfProjectRole(ProjectRole::Member), RolePermissions::grantableToProjectRole(ProjectRole::Member)],
            'PV' => [RolePermissions::defaultsOfProjectRole(ProjectRole::Viewer), RolePermissions::grantableToProjectRole(ProjectRole::Viewer)],
            default => throw new \LogicException('Unknown role '.$role),
        };

        self::assertSame('default' === $kind, \in_array($permission, $defaults, true), 'default');
        self::assertSame('grantable' === $kind, \in_array($permission, $grantable, true), 'grantable');
    }

    #[Test]
    public function it_should_never_put_an_instance_permission_in_a_role(): void
    {
        foreach (TeamRole::cases() as $role) {
            foreach ([...RolePermissions::defaultsOfTeamRole($role), ...RolePermissions::grantableToTeamRole($role)] as $permission) {
                self::assertNotSame(PermissionScope::Instance, $permission->scope());
            }
        }
    }
}
