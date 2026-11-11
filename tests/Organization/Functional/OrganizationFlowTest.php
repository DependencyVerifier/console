<?php

declare(strict_types=1);

namespace Tests\Organization\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

use function Symfony\Component\String\u;

final class OrganizationFlowTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_create_a_team_with_a_new_first_admin_and_let_them_manage_it(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/teams');
        self::assertResponseIsSuccessful();
        $this->createTeam('Platform', 'jane@example.com');
        self::assertResponseIsSuccessful();
        $link = $this->setupLink();
        $teamId = $this->fetchString('SELECT id FROM organization_team');
        self::assertCount(1, AuditLog::entries($this->connection(), 'organization.create_team'));

        $draw = DataTables::draw($this->client, '/admin/teams', 'teams');
        self::assertSame(1, $draw['recordsTotal']);
        self::assertStringContainsString('Platform', $draw['data'][0]['name']);
        self::assertSame(['1', '0'], [u($draw['data'][0]['members'])->trim()->toString(), u($draw['data'][0]['projects'])->trim()->toString()], 'The first admin is a member at once; zero is shown too.');

        // The new account, in a fresh browser: it chooses its password, signs in, and already manages the team.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful('A same-site hop, never a redirect.');
        $crawler = $this->client->clickLink('Continue');
        $this->client->submit($crawler->filter('form')->form([
            'new_password[password][first]' => Accounts::PASSWORD,
            'new_password[password][second]' => Accounts::PASSWORD,
        ]));
        self::assertResponseRedirects('/login');
        $this->logIn('jane@example.com');
        $this->client->request('GET', '/teams/'.$teamId);

        // Jane is TEAM_ADMIN: she creates a project, an environment, and hits the environment quota.
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('turbo-frame#create-project-form[src="/teams/%s/projects/new"]', $teamId), 'The "New project" shortcut of the overview.');
        $frame = ['HTTP_TURBO_FRAME' => 'create-project-form'];
        $project = $this->client->request('GET', \sprintf('/teams/%s/projects/new', $teamId), server: $frame)->filter('turbo-frame#create-project-form form')->form(['project[name]' => 'Shop']);
        $this->client->request('POST', $project->getUri(), $project->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        self::assertResponseIsSuccessful();
        $projectId = $this->fetchString('SELECT id FROM organization_project');
        $members = $this->fetchInt('SELECT COUNT(*) FROM organization_project_membership');
        self::assertSame(0, $members, 'A TEAM_ADMIN creator gets no project membership.');

        $this->client->request('GET', \sprintf('/teams/%s/projects/%s', $teamId, $projectId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('turbo-frame#create-environment-form[src="/teams/%s/projects/%s/environments/new"]', $teamId, $projectId));
        $frame = ['HTTP_TURBO_FRAME' => 'create-environment-form'];
        foreach (['prod', 'staging', 'dev', 'qa'] as $i => $name) {
            $environment = $this->client->request('GET', \sprintf('/teams/%s/projects/%s/environments/new', $teamId, $projectId), server: $frame)->filter('turbo-frame#create-environment-form form')->form(['environment[name]' => $name]);
            $this->client->request('POST', $environment->getUri(), $environment->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
            self::assertResponseStatusCodeSame($i < 3 ? 200 : 422, $name);
        }

        $refused = array_values(array_filter(AuditLog::entries($this->connection(), 'organization.create_environment'), static fn (array $e): bool => 'denied' === $e['outcome']));
        self::assertSame('rule:quota', $refused[0]['reason']);
        self::assertSame($teamId, $refused[0]['team_id'], 'Scope resolved from the real project.');

        $draw = DataTables::draw($this->client, \sprintf('/teams/%s/projects/%s/environments', $teamId, $projectId), 'environments');
        self::assertSame(3, $draw['recordsTotal']);
    }

    #[Test]
    public function it_should_hide_a_team_from_a_non_member(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        Accounts::create(self::getContainer(), 'john@example.com');
        $this->logIn('root@example.com');
        $this->createTeam('Platform', 'jane@example.com');
        $teamId = $this->fetchString('SELECT id FROM organization_team');

        $this->client->getCookieJar()->clear();
        $this->logIn('john@example.com');
        $this->client->request('GET', '/teams/'.$teamId);

        self::assertResponseStatusCodeSame(404);
        $denied = AuditLog::entries($this->connection(), 'query.denied');
        self::assertCount(1, $denied);
        self::assertSame('not_found', $denied[0]['reason']);
    }

    /**
     * Through the "Create a team" dialog: the form comes from its endpoint into the dialog frame, then is posted.
     */
    private function createTeam(string $name, string $firstAdminEmail): void
    {
        $frame = ['HTTP_TURBO_FRAME' => 'create-team-form'];
        $crawler = $this->client->request('GET', '/admin/teams/new', server: $frame);
        $form = $crawler->filter('turbo-frame#create-team-form form')->form(['team[name]' => $name, 'team[firstAdminEmail]' => $firstAdminEmail]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
    }

    private function setupLink(): string
    {
        $setup = array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email && 'Your DependencyVerifier account' === $m->getSubject()));
        self::assertCount(1, $setup);
        $match = u((string) $setup[0]->getTextBody())->match('#(https://localhost/password/reset/[A-Za-z0-9_-]{43})#');
        self::assertIsString($match[1] ?? null);

        return $match[1];
    }
}
