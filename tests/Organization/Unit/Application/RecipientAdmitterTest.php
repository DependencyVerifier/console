<?php

declare(strict_types=1);

namespace Tests\Organization\Unit\Application;

use Organization\Application\Membership\RecipientAdmitter;
use Organization\Domain\Authorization\EffectivePermissions;
use Organization\Domain\Authorization\ProjectGrant;
use Organization\Domain\Authorization\TeamGrant;
use Organization\Domain\Model\PermissionGrant;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Tests\Organization\Support\FixedMembers;
use Tests\Organization\Support\Teams;
use Tests\Organization\Support\World;

/**
 * A confirmed notification recipient attached to a project on behalf of the manager who invited it: PROJECT_VIEWER,
 * and TEAM_GUEST when not a member, under the team lock, if the manager still holds `project.notifications.manage`.
 */
final class RecipientAdmitterTest extends TestCase
{
    private const string RECIPIENT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f09';

    #[Test]
    public function it_should_admit_a_recipient_as_guest_of_the_team_and_viewer_of_the_project(): void
    {
        $world = $this->world();
        $admitter = $this->admitter($world, [Teams::ADMIN => [new TeamGrant(Teams::TEAM, TeamRole::Admin)]]);

        $admitter->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT, Teams::ADMIN);
        $admitter->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT, Teams::ADMIN);

        self::assertSame(TeamRole::Guest, $world->teams->teams[Teams::TEAM]->membershipOf($world->uuid(self::RECIPIENT))->role());
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
        $world = $this->world();
        $world->teams->teams[Teams::TEAM]->admit($world->uuid(self::RECIPIENT), TeamRole::Viewer, $world->clock->now());

        $this->admitter($world, [Teams::MEMBER => [], 'projects' => [new ProjectGrant(Teams::PROJECT, Teams::TEAM, ProjectRole::Admin)]])
            ->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT, Teams::MEMBER);

        self::assertSame(TeamRole::Viewer, $world->teams->teams[Teams::TEAM]->membershipOf($world->uuid(self::RECIPIENT))->role());
        self::assertCount(1, $world->grants->grants);
    }

    #[Test]
    public function it_should_refuse_when_the_manager_may_no_longer_or_the_project_is_gone(): void
    {
        $world = $this->world();
        $world->teams->add(Teams::team(Teams::OTHER_TEAM));
        $viewer = [Teams::MEMBER => [new TeamGrant(Teams::TEAM, TeamRole::Viewer)]];
        $admin = [Teams::ADMIN => [new TeamGrant(Teams::TEAM, TeamRole::Admin), new TeamGrant(Teams::OTHER_TEAM, TeamRole::Admin)]];

        $this->assertRefused(PermissionDenied::class, fn () => $this->admitter($world, $viewer)->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT, Teams::MEMBER));
        $this->assertRefused(PermissionDenied::class, fn () => $this->admitter($world, $admin)->admitRecipient(Teams::TEAM, Teams::PROJECT, self::RECIPIENT, Teams::GUEST), 'Another account.');
        $this->assertRefused(ResourceNotFound::class, fn () => $this->admitter($world, $admin)->admitRecipient(Teams::OTHER_TEAM, Teams::PROJECT, self::RECIPIENT, Teams::ADMIN), 'A project of another team.');
        $this->assertRefused(ResourceNotFound::class, fn () => $this->admitter($world, $admin)->admitRecipient(Teams::TEAM, '019a6b2c-3f00-7a11-8b22-3c44d55e6f09', self::RECIPIENT, Teams::ADMIN), 'A project gone.');
        $this->assertRefused(ResourceNotFound::class, fn () => $this->admitter($world, $admin)->admitRecipient('019a6b2c-2f00-7a11-8b22-3c44d55e6f09', Teams::PROJECT, self::RECIPIENT, Teams::ADMIN), 'A team gone.');

        self::assertFalse($world->teams->teams[Teams::TEAM]->isMember($world->uuid(self::RECIPIENT)));
        self::assertSame([], $world->grants->grants);
    }

    /**
     * @param class-string<\Throwable> $refusal
     * @param \Closure(): mixed        $gesture
     */
    private function assertRefused(string $refusal, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail($message);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown, $message);
        }
    }

    /**
     * @param array<string, list<TeamGrant|ProjectGrant>> $grants by account ('projects': the project grants of the first one)
     */
    private function admitter(World $world, array $grants): RecipientAdmitter
    {
        $projects = array_values(array_filter($grants['projects'] ?? [], static fn (object $g): bool => $g instanceof ProjectGrant));
        unset($grants['projects']);
        $members = [];
        foreach ($grants as $userId => $teams) {
            $members[$userId] = EffectivePermissions::resolve(false, array_values(array_filter($teams, static fn (object $g): bool => $g instanceof TeamGrant)), $projects);
        }

        return new RecipientAdmitter($world->teams, $world->projects, new FixedMembers($members), $world->grants, $world->clock);
    }

    private function world(): World
    {
        $world = new World();
        $world->teams->add(Teams::withAdmin());
        $world->projects->add(Teams::project());

        return $world;
    }
}
