<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Organization\Domain\Model\Invitation;
use Organization\Domain\Model\InvitationId;
use Organization\Domain\Model\InvitationTarget;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * Members and pending invitations, two lists everywhere: the team, a project, and the instance (/admin/users). The
 * member lists are searched by email too.
 */
final class InvitationListsTest extends IdentityWebTestCase
{
    #[Test]
    public function it_should_list_the_pending_invitations_of_a_project_and_of_the_instance(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop');
        $this->invite(InvitationTarget::team($team->id(), TeamRole::Member), 'team@example.com', Seed::uuid($alice));
        $this->invite(InvitationTarget::project($team->id(), $project->id(), ProjectRole::Viewer), 'project@example.com', Seed::uuid($alice));
        [$teamId, $projectId] = [$team->id()->toString(), $project->id()->toString()];

        $this->logIn('alice@example.com');
        $draw = DataTables::draw($this->client, \sprintf('/teams/%s/projects/%s/members', $teamId, $projectId), 'invitations');
        self::assertSame(1, $draw['recordsTotal'], 'Only the project\'s invitation.');
        self::assertStringContainsString('project@example.com', $draw['data'][0]['email']);
        self::assertSame(2, DataTables::draw($this->client, '/teams/'.$teamId.'/members', 'invitations')['recordsTotal'], 'The team lists those of its projects too.');

        $this->client->getCookieJar()->clear();
        $this->logIn('root@example.com');
        $this->client->request('GET', '/admin/users');
        self::assertSelectorExists('[data-datatable-name-value="users"]');
        self::assertSelectorExists('[data-datatable-name-value="invitations"]');
        $draw = DataTables::draw($this->client, '/admin/users', 'invitations');
        self::assertSame(2, $draw['recordsTotal']);
        $targets = array_map(static fn (array $r): string => DataTables::cell($r['team'])->text(), $draw['data']);
        sort($targets);
        self::assertSame(['Alpha', 'Alpha / Shop'], $targets);
        $actions = DataTables::cell($draw['data'][0]['actions']);
        self::assertCount(1, $actions->filter(\sprintf('form[action^="/teams/%s/invitations/"][action$="/revoke"]', $teamId)), 'Revoked from its team.');
        self::assertSame(1, DataTables::draw($this->client, '/admin/users', 'invitations', 'project@')['recordsFiltered']);
    }

    #[Test]
    public function it_should_search_the_members_by_email(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$bob, TeamRole::Member]]);
        $this->logIn('alice@example.com');

        $draw = DataTables::draw($this->client, '/teams/'.$team->id()->toString().'/members', 'team_members', 'bob@');
        self::assertSame([2, 1], [$draw['recordsTotal'], $draw['recordsFiltered']]);
        self::assertStringContainsString('bob@example.com', $draw['data'][0]['email']);
        self::assertSame(1, DataTables::draw($this->client, '/teams/'.$team->id()->toString().'/members', 'team_members', 'team_admin')['recordsFiltered'], 'By role too.');
    }

    private function invite(InvitationTarget $target, string $email, Uuid $by): void
    {
        $invitation = Invitation::issue(InvitationId::of(Uuid::v7()), $email, $target, $by, 't', 'h:'.$email, new \DateTimeImmutable());
        $invitation->releaseEvents();
        Accounts::manager(self::getContainer())->persist($invitation);
        Accounts::manager(self::getContainer())->flush();
    }
}
