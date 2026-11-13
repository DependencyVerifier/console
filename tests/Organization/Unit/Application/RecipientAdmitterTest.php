<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Membership\RecipientAdmitter;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Tests\Organization\Support\FixedPermissions;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

/**
 * A notification recipient attached to a project: PROJECT_VIEWER, and TEAM_GUEST when not a member, under the team
 * lock and `project.notifications.manage`; idempotent.
 */
final class RecipientAdmitterTest extends TestCase
{
    private const string RECIPIENT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f09';

    #[Test]
    public function it_should_admit_a_recipient_as_guest_of_the_team_and_viewer_of_the_project(): void
    {
        $world = $this->world()->as(Teams::ADMIN, TeamRole::Admin);
        $admitter = new RecipientAdmitter($world->access(), $world->grants, $world->clock);

        $admitter->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT);
        $admitter->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT);

        $team = $world->teams->teams[Teams::TEAM];
        self::assertSame(TeamRole::Guest, $team->membershipOf($world->uuid(self::RECIPIENT))->role());
        self::assertSame(ProjectRole::Viewer, $world->projects->projects[Teams::PROJECT]->membershipOf($world->uuid(self::RECIPIENT))->role());
        self::assertSame([Teams::TEAM, Teams::TEAM], $world->teams->locked, 'Under the team lock, each time.');
        self::assertSame([
            [self::RECIPIENT, 'role_assigned', 'team_guest'],
            [self::RECIPIENT, 'role_assigned', 'project_viewer'],
        ], array_map(static fn (PermissionGrant $g): array => [$g->userId()->toRfc4122(), $g->change()->value, $g->value()], $world->grants->grants), 'Recorded once.');
    }

    #[Test]
    public function it_should_keep_the_role_of_a_member(): void
    {
        $world = $this->world()->as(Teams::ADMIN, TeamRole::Guest, ProjectRole::Admin);
        $world->teams->teams[Teams::TEAM]->admit($world->uuid(self::RECIPIENT), TeamRole::Viewer, $world->clock->now());

        new RecipientAdmitter($world->access(), $world->grants, $world->clock)->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT);

        self::assertSame(TeamRole::Viewer, $world->teams->teams[Teams::TEAM]->membershipOf($world->uuid(self::RECIPIENT))->role());
        self::assertCount(1, $world->grants->grants);
    }

    #[Test]
    public function it_should_refuse_without_the_permission_or_out_of_scope(): void
    {
        $world = $this->world()->as(Teams::MEMBER, TeamRole::Viewer);
        $this->assertRefused($world, PermissionDenied::class);

        $world->as(Teams::MEMBER, TeamRole::Guest, ProjectRole::Viewer);
        $this->assertRefused($world, PermissionDenied::class, 'A viewer of the project.');

        $world->as(Teams::GUEST, TeamRole::Guest);
        $this->assertRefused($world, ResourceNotFound::class, 'A guest does not see the project.');

        $world->as(Teams::ADMIN, TeamRole::Admin, teamId: Teams::OTHER_TEAM);
        $this->assertRefused($world, ResourceNotFound::class, 'Another team.');

        $world->teams->add(Teams::team(Teams::OTHER_TEAM));
        $world->permissions = new FixedPermissions(false, [new TeamGrant(Teams::TEAM, TeamRole::Admin), new TeamGrant(Teams::OTHER_TEAM, TeamRole::Admin)]);
        try {
            new RecipientAdmitter($world->access(), $world->grants, $world->clock)->admitRecipient(Teams::OTHER_TEAM, Teams::PROJECT, self::RECIPIENT);
            self::fail('A project of another team.');
        } catch (ResourceNotFound) {
            $this->addToAssertionCount(1);
        }

        self::assertSame([], $world->grants->grants);
    }

    /**
     * @param class-string<\Throwable> $refusal
     */
    private function assertRefused(World $world, string $refusal, string $message = ''): void
    {
        try {
            new RecipientAdmitter($world->access(), $world->grants, $world->clock)->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT);
            self::fail($message);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown, $message);
        }

        self::assertFalse($world->teams->teams[Teams::TEAM]->isMember($world->uuid(self::RECIPIENT)), $message);
    }

    private function world(): World
    {
        $world = new World();
        $world->teams->add(Teams::withAdmin());
        $world->projects->add(Teams::project());

        return $world;
    }
}
