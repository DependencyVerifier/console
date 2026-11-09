<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Domain\Model;

use Organization\Domain\Model\AlreadyMember;
use Organization\Domain\Model\DuplicateEnvironmentName;
use Organization\Domain\Model\EnvironmentId;
use Organization\Domain\Model\EnvironmentNotFound;
use Organization\Domain\Model\InvalidName;
use Organization\Domain\Model\MembershipStatus;
use Organization\Domain\Model\NotAMember;
use Organization\Domain\Model\PermissionNotGrantable;
use Organization\Domain\Model\Project;
use Organization\Domain\Model\ProjectId;
use Organization\Domain\Model\ProjectNotEmpty;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamId;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Uid\Uuid;
use Tests\Organization\Support\Teams;

use function Symfony\Component\String\u;

final class ProjectTest extends TestCase
{
    private const string ENV = '019a6b2c-4f00-7a11-8b22-3c44d55e6f01';
    private const string ENV_2 = '019a6b2c-4f00-7a11-8b22-3c44d55e6f02';

    #[Test]
    public function it_should_make_the_creator_project_admin_unless_covered(): void
    {
        foreach ([TeamRole::Member, TeamRole::Viewer, TeamRole::Guest] as $role) {
            $project = $this->createBy($role);
            self::assertSame(ProjectRole::Admin, $project->membershipOf(Teams::uuid(Teams::MEMBER))->role(), $role->value);
            self::assertEquals(Teams::now(), $project->membershipOf(Teams::uuid(Teams::MEMBER))->joinedAt());
        }

        self::assertSame([], $this->createBy(TeamRole::Admin)->memberships(), 'TEAM_ADMIN already covers it.');
        self::assertSame([], $this->createBy(null)->memberships(), 'Super admin without team role.');
    }

    #[Test]
    public function it_should_hold_its_identity(): void
    {
        $project = $this->createBy(null);

        self::assertSame(Teams::PROJECT, $project->id()->toString());
        self::assertSame(Teams::TEAM, $project->teamId()->toString());
        self::assertSame('Shop', $project->name());
        self::assertSame('shop', $project->slug());
        self::assertEquals(Teams::now(), $project->createdAt());
        $project->rename(' Store ');
        self::assertSame('Store', $project->name());

        $this->expectException(InvalidName::class);
        $project->rename(u('x')->repeat(121)->toString());
    }

    #[Test]
    public function it_should_manage_memberships_and_extras(): void
    {
        $project = $this->createBy(null);
        $membership = $project->admit(Teams::uuid(Teams::GUEST), ProjectRole::Member, Teams::now());

        self::assertTrue($project->isMember(Teams::uuid(Teams::GUEST)));
        self::assertSame($project, $membership->project());
        self::assertTrue($membership->userId()->equals(Teams::uuid(Teams::GUEST)));
        self::assertSame(MembershipStatus::Active, $membership->status());

        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::FindingAcceptRisk);
        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::ProjectEdit);
        self::assertSame([Permission::FindingAcceptRisk, Permission::ProjectEdit], $membership->extraPermissions());
        $project->revokeExtra(Teams::uuid(Teams::GUEST), Permission::ProjectEdit);
        self::assertSame([Permission::FindingAcceptRisk], $membership->extraPermissions());

        $project->changeRole(Teams::uuid(Teams::GUEST), ProjectRole::Viewer);
        self::assertSame(ProjectRole::Viewer, $membership->role());
        self::assertSame([], $membership->extraPermissions(), 'Not ○ for a viewer.');

        $project->suspendMember(Teams::uuid(Teams::GUEST));
        $project->suspendMember(Teams::uuid(Teams::ADMIN));
        self::assertFalse($membership->isActive());
        $project->restoreMember(Teams::uuid(Teams::GUEST));
        $project->restoreMember(Teams::uuid(Teams::ADMIN));
        self::assertTrue($membership->isActive());

        $project->removeMember(Teams::uuid(Teams::GUEST));
        self::assertFalse($project->isMember(Teams::uuid(Teams::GUEST)));

        $this->expectException(NotAMember::class);
        $project->membershipOf(Teams::uuid(Teams::GUEST));
    }

    #[Test]
    public function it_should_refuse_a_duplicate_member_or_a_non_grantable_extra(): void
    {
        $project = $this->createBy(null);
        $project->admit(Teams::uuid(Teams::GUEST), ProjectRole::Viewer, Teams::now());

        foreach ([Permission::ProjectEdit, Permission::HostEnroll, Permission::InstanceSettings] as $permission) {
            try {
                $project->grantExtra(Teams::uuid(Teams::GUEST), $permission);
                self::fail($permission->value);
            } catch (PermissionNotGrantable) {
            }
        }

        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::ProjectScanNow);
        $this->expectException(AlreadyMember::class);
        $project->admit(Teams::uuid(Teams::GUEST), ProjectRole::Admin, Teams::now());
    }

    #[Test]
    public function it_should_manage_environments_with_unique_names(): void
    {
        $project = $this->createBy(null);
        $prod = $project->addEnvironment(EnvironmentId::fromString(self::ENV), ' Production ', true);
        $project->addEnvironment(EnvironmentId::fromString(self::ENV_2), 'staging', false);

        self::assertSame('Production', $prod->name());
        self::assertTrue($prod->isProduction());
        self::assertSame($project, $prod->project());
        self::assertSame(self::ENV, $prod->id()->toString());
        self::assertSame(2, $project->environmentCount());
        self::assertCount(2, $project->environments());
        self::assertSame($prod, $project->environment(EnvironmentId::fromString(self::ENV)));

        try {
            $project->addEnvironment(EnvironmentId::of(Uuid::v7()), 'PRODUCTION', false);
            self::fail('Duplicate, case-insensitive.');
        } catch (DuplicateEnvironmentName) {
        }

        $project->updateEnvironment(EnvironmentId::fromString(self::ENV), 'production', false);
        self::assertSame('production', $prod->name(), 'Renaming itself is fine.');
        self::assertFalse($prod->isProduction());

        try {
            $project->updateEnvironment(EnvironmentId::fromString(self::ENV_2), 'Production', true);
            self::fail('Duplicate on update.');
        } catch (DuplicateEnvironmentName) {
        }

        $project->removeEnvironment(EnvironmentId::fromString(self::ENV_2));
        self::assertSame(1, $project->environmentCount());

        $this->expectException(EnvironmentNotFound::class);
        $project->environment(EnvironmentId::fromString(self::ENV_2));
    }

    #[Test]
    public function it_should_be_deletable_only_without_environments(): void
    {
        $project = $this->createBy(null);
        $project->assertDeletable();
        $project->addEnvironment(EnvironmentId::fromString(self::ENV), 'prod', true);

        $this->expectException(ProjectNotEmpty::class);
        $project->assertDeletable();
    }

    #[Test]
    public function it_should_refuse_an_empty_environment_name(): void
    {
        $this->expectException(InvalidName::class);

        $this->createBy(null)->addEnvironment(EnvironmentId::fromString(self::ENV), u('x')->repeat(61)->toString(), true);
    }

    private function createBy(?TeamRole $role): Project
    {
        return Project::create(ProjectId::fromString(Teams::PROJECT), TeamId::fromString(Teams::TEAM), 'Shop', 'shop', Teams::uuid(Teams::MEMBER), $role, Teams::now());
    }

    #[Test]
    public function it_should_keep_lists_and_stored_extras_reindexed(): void
    {
        $project = $this->createBy(TeamRole::Member);
        $membership = $project->admit(Teams::uuid(Teams::GUEST), ProjectRole::Member, Teams::now());
        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::ProjectEdit);
        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::FindingAcceptRisk);
        $project->changeRole(Teams::uuid(Teams::GUEST), ProjectRole::Viewer);
        self::assertSame([], new \ReflectionProperty($membership, 'extraPermissions')->getValue($membership));

        $project->addEnvironment(EnvironmentId::fromString(self::ENV), 'a', false);
        $second = $project->addEnvironment(EnvironmentId::fromString(self::ENV_2), 'b', false);
        $project->removeEnvironment(EnvironmentId::fromString(self::ENV));
        self::assertSame([$second], $project->environments());

        $project->removeMember(Teams::uuid(Teams::MEMBER));
        self::assertSame([$membership], $project->memberships());
    }

    #[Test]
    public function it_should_keep_a_project_extra_when_the_new_role_may_hold_it(): void
    {
        $project = $this->createBy(null);
        $membership = $project->admit(Teams::uuid(Teams::GUEST), ProjectRole::Member, Teams::now());
        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::ProjectEdit);
        $project->grantExtra(Teams::uuid(Teams::GUEST), Permission::FindingAcceptRisk);
        $project->changeRole(Teams::uuid(Teams::GUEST), ProjectRole::Member);

        self::assertSame(['project.edit', 'finding.accept_risk'], new \ReflectionProperty($membership, 'extraPermissions')->getValue($membership));
    }
}
