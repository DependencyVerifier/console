<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Command\Environment\CreateEnvironment;
use Organization\Application\Command\Invitation\InviteMember;
use Organization\Application\Command\Member\ChangeProjectRole;
use Organization\Application\Command\Member\ChangeTeamRole;
use Organization\Application\Command\Member\GrantExtraPermission;
use Organization\Application\Command\Member\RemoveProjectMember;
use Organization\Application\Command\Member\RemoveTeamMember;
use Organization\Application\Command\Member\RevokeExtraPermission;
use Organization\Application\Command\Project\CreateProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\AuditableCommand;
use Tests\Organization\Support\Teams;

/**
 * What each Organization command writes in the audit log: its action, its allowed context keys, its target and
 * its context (never a secret, and creations audited on their parent).
 */
final class CommandAuditTest extends TestCase
{
    /**
     * @return iterable<string, array{AuditableCommand, string, list<string>, string, string, array<string, scalar>}>
     */
    public static function commands(): iterable
    {
        $u = Teams::MEMBER;
        $t = Teams::TEAM;
        $p = Teams::PROJECT;
        $i = '019a6b2c-5f00-7a11-8b22-3c44d55e6f01';

        yield 'team invitation' => [new InviteMember($i, $t, null, 'a@example.com', 'team_member'), 'organization.invite_member', ['role', 'invitation_id'], 'team', $t, ['role' => 'team_member', 'invitation_id' => $i]];
        yield 'project invitation' => [new InviteMember($i, $t, $p, 'a@example.com', 'project_viewer'), 'organization.invite_member', ['role', 'invitation_id'], 'project', $p, ['role' => 'project_viewer', 'invitation_id' => $i]];
        yield 'team role' => [new ChangeTeamRole($t, $u, 'team_viewer'), 'organization.change_team_role', ['user_id', 'role'], 'team', $t, ['user_id' => $u, 'role' => 'team_viewer']];
        yield 'project role' => [new ChangeProjectRole($p, $u, 'project_admin'), 'organization.change_project_role', ['user_id', 'role'], 'project', $p, ['user_id' => $u, 'role' => 'project_admin']];
        yield 'team extra granted' => [new GrantExtraPermission($t, null, $u, 'host.enroll'), 'organization.grant_extra_permission', ['user_id', 'permission'], 'team', $t, ['user_id' => $u, 'permission' => 'host.enroll']];
        yield 'project extra granted' => [new GrantExtraPermission($t, $p, $u, 'project.scan_now'), 'organization.grant_extra_permission', ['user_id', 'permission'], 'project', $p, ['user_id' => $u, 'permission' => 'project.scan_now']];
        yield 'team extra revoked' => [new RevokeExtraPermission($t, null, $u, 'host.enroll'), 'organization.revoke_extra_permission', ['user_id', 'permission'], 'team', $t, ['user_id' => $u, 'permission' => 'host.enroll']];
        yield 'project extra revoked' => [new RevokeExtraPermission($t, $p, $u, 'project.scan_now'), 'organization.revoke_extra_permission', ['user_id', 'permission'], 'project', $p, ['user_id' => $u, 'permission' => 'project.scan_now']];
        yield 'team member removed' => [new RemoveTeamMember($t, $u), 'organization.remove_team_member', ['user_id'], 'team', $t, ['user_id' => $u]];
        yield 'project member removed' => [new RemoveProjectMember($p, $u), 'organization.remove_project_member', ['user_id'], 'project', $p, ['user_id' => $u]];
        yield 'project created' => [new CreateProject($p, $t, 'Shop'), 'organization.create_project', ['project_id'], 'team', $t, ['project_id' => $p]];
        yield 'environment created' => [new CreateEnvironment($i, $p, 'prod', true), 'organization.create_environment', ['environment_id'], 'project', $p, ['environment_id' => $i]];
    }

    /**
     * @param list<string>          $keys
     * @param array<string, scalar> $context
     */
    #[Test]
    #[DataProvider('commands')]
    public function it_should_describe_its_audit_entry(AuditableCommand $command, string $action, array $keys, string $targetType, string $targetId, array $context): void
    {
        self::assertSame($action, $command->auditAction()->name);
        self::assertSame($keys, $command->auditAction()->allowedContextKeys);
        self::assertSame($targetType, $command->auditTarget()->type);
        self::assertSame($targetId, $command->auditTarget()->id());
        self::assertSame($context, $command->auditContext()->values);
    }
}
