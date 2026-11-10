<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

/**
 * Scope and guard rules seen through HTTP: two teams, a guest, the last admin, CSRF, the quota frame, invitations.
 */
final class OrganizationAccessTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_hide_another_team_and_every_object_of_it(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $alpha = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$bob, TeamRole::Member]]);
        $beta = Seed::team(self::getContainer(), 'Beta', [[$bob, TeamRole::Admin]]);
        $vault = Seed::project(self::getContainer(), $beta, 'Vault');
        [$a, $b, $p, $bobId] = [$alpha->id()->toString(), $beta->id()->toString(), $vault->id()->toString(), $bob->id()->toString()];
        $this->logIn('alice@example.com');

        foreach (['/teams/'.$b, \sprintf('/teams/%s/projects/%s', $a, $p), \sprintf('/teams/%s/projects/%s', $b, $p)] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        $this->client->request('GET', \sprintf('/teams/%s/frames/quotas', $b), server: ['HTTP_TURBO_FRAME' => 'team-quotas']);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('<turbo-frame id="team-quotas"', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/teams/'.$b.'/members', ['_dt' => 'team_members', 'draw' => 1, 'start' => 0, 'length' => 25]);
        self::assertResponseStatusCodeSame(404, 'No draw on another team.');

        // A token for "remove Bob" is legitimately obtained on Alpha; replayed on Beta, the handler judges the real team.
        $row = array_values(array_filter(DataTables::draw($this->client, '/teams/'.$a.'/members', 'team_members')['data'], static fn (array $r): bool => u($r['email'])->containsAny('bob@example.com')));
        $form = DataTables::cell($row[0]['actions'])->filter('form')->form();
        $this->client->request('POST', \sprintf('/teams/%s/members/%s/remove', $b, $bobId), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM organization_team_membership WHERE team_id = ? AND user_id = ?', [$b, $bobId]));

        $this->client->request('GET', '/admin/teams');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_show_a_guest_only_its_projects(): void
    {
        $admin = Accounts::create(self::getContainer(), 'alice@example.com');
        $guest = Accounts::create(self::getContainer(), 'carol@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$admin, TeamRole::Admin], [$guest, TeamRole::Guest]]);
        $shop = Seed::project(self::getContainer(), $team, 'Shop', [[$guest, ProjectRole::Viewer]]);
        $vault = Seed::project(self::getContainer(), $team, 'Vault');
        $teamId = $team->id()->toString();
        $this->logIn('carol@example.com');

        $this->client->request('GET', '/teams/'.$teamId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(\sprintf('nav a[href="/teams/%s/members"]', $teamId), 'A guest has no Members tab.');
        $this->client->request('GET', \sprintf('/teams/%s/members', $teamId));
        self::assertResponseStatusCodeSame(403, 'Nor its URL.');
        $draw = DataTables::draw($this->client, '/teams/'.$teamId.'/projects', 'projects');
        self::assertSame(1, $draw['recordsTotal']);
        self::assertStringContainsString('Shop', $draw['data'][0]['name']);

        $this->client->request('GET', \sprintf('/teams/%s/projects/%s', $teamId, $shop->id()->toString()));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', \sprintf('/teams/%s/projects/%s', $teamId, $vault->id()->toString()));
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_keep_the_last_team_admin(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $teamId = $team->id()->toString();
        $this->logIn('alice@example.com');

        $row = DataTables::draw($this->client, '/teams/'.$teamId.'/members', 'team_members')['data'][0];
        $form = DataTables::cell($row['role'])->filter('form')->form(['role[role]' => 'team_member']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('team_admin', $this->connection()->fetchOne('SELECT role FROM organization_team_membership WHERE team_id = ?', [$teamId]));
        $denied = array_values(array_filter(AuditLog::entries($this->connection(), 'organization.change_team_role'), static fn (array $e): bool => 'denied' === $e['outcome']));
        self::assertCount(1, $denied);
        self::assertSame('rule:last_team_admin_required', $denied[0]['reason']);
    }

    #[Test]
    public function it_should_reject_an_organization_action_without_its_token(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $teamId = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]])->id()->toString();
        $this->logIn('alice@example.com');

        $this->client->request('POST', \sprintf('/teams/%s/rename', $teamId), ['team_name' => ['name' => 'Omega']], server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, AuditLog::entries($this->connection(), 'csrf.rejected'));
        self::assertSame('Alpha', $this->connection()->fetchOne('SELECT name FROM organization_team'));
        self::assertSame([], AuditLog::entries($this->connection(), 'organization.rename_team'));
    }

    #[Test]
    public function it_should_serve_the_quota_frame_only_inside_its_page(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $teamId = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]])->id()->toString();
        $this->logIn('alice@example.com');

        $this->client->request('GET', \sprintf('/teams/%s/frames/quotas', $teamId));
        self::assertResponseRedirects('/teams/'.$teamId, 303);

        $this->client->request('GET', \sprintf('/teams/%s/frames/quotas', $teamId), server: ['HTTP_TURBO_FRAME' => 'team-quotas']);
        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('Turbo-Frame', implode(',', $headers->all('vary')));
        self::assertSelectorExists('turbo-frame#team-quotas meter');
    }

    #[Test]
    public function it_should_add_a_team_guest_when_a_project_invitation_is_accepted(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $john = Accounts::create(self::getContainer(), 'john@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop');
        [$teamId, $projectId] = [$team->id()->toString(), $project->id()->toString()];
        $this->logIn('alice@example.com');
        $link = $this->invite(\sprintf('/teams/%s/projects/%s', $teamId, $projectId), 'john@example.com', 'project_viewer');

        $this->client->getCookieJar()->clear();
        $this->logIn('john@example.com');
        $this->client->request('GET', $link);
        $crawler = $this->client->clickLink('Continue');
        $this->client->submit($crawler->filter('form[action="/invitations/accept"]')->form());

        self::assertResponseRedirects('/teams/'.$teamId);
        $johnId = $john->id()->toString();
        self::assertSame('team_guest', $this->connection()->fetchOne('SELECT role FROM organization_team_membership WHERE user_id = ?', [$johnId]));
        self::assertSame('project_viewer', $this->connection()->fetchOne('SELECT role FROM organization_project_membership WHERE user_id = ?', [$johnId]));
        self::assertStringNotContainsString(u($link)->afterLast('/')->toString(), (string) $this->client->getRequest()->getUri(), 'No token in the final URL.');
    }

    #[Test]
    public function it_should_refuse_an_invitation_for_another_email_or_an_expired_one(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        Accounts::create(self::getContainer(), 'john@example.com');
        $teamId = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]])->id()->toString();
        $this->logIn('alice@example.com');
        $link = $this->invite('/teams/'.$teamId, 'jane@example.com', 'team_member');

        $this->client->getCookieJar()->clear();
        $this->logIn('john@example.com');
        $this->client->request('GET', $link);
        $this->client->clickLink('Continue');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/invitations/accept"]', 'Signed in as someone else: nothing to accept.');

        self::assertSame('pending', $this->connection()->fetchOne('SELECT status FROM organization_invitation'));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM organization_team_membership WHERE role = ?', ['team_member']));

        $this->connection()->executeStatement("UPDATE organization_invitation SET issued_at = issued_at - INTERVAL '8 days'");
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410);
    }

    /**
     * A team invitation goes through the "Invite member" dialog of the team page, a project one through the form of
     * the project page.
     */
    private function invite(string $page, string $email, string $role): string
    {
        if (u($page)->containsAny('/projects/')) {
            $form = $this->client->request('GET', $page)->filter('form[action$="/invite"]')->form(['invite[email]' => $email, 'invite[role]' => $role]);
            $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        } else {
            $frame = ['HTTP_TURBO_FRAME' => 'invite-member-form'];
            $form = $this->client->request('GET', $page.'/members/invite', server: $frame)->filter('turbo-frame#invite-member-form form')->form(['invite[email]' => $email, 'invite[role]' => $role]);
            $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        }

        self::assertResponseIsSuccessful();

        $messages = self::getMailerMessages();
        $message = end($messages);
        self::assertInstanceOf(Email::class, $message);
        $match = u((string) $message->getTextBody())->match('#(https://localhost/invitations/[A-Za-z0-9_-]{43})#');
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }
}
