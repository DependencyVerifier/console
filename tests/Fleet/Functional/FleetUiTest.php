<?php

declare(strict_types=1);

namespace Tests\Fleet\Functional;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetSeed;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * The fleet sections of a team: a token created in the dialog and shown once with the Compose snippet, an agent
 * enrolled with it, approved after comparing its fingerprint, then revoked; the same pages refused to a TEAM_GUEST
 * and to another team.
 */
final class FleetUiTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_create_a_token_enroll_approve_and_revoke_an_agent(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop');
        $teamId = $team->id()->toString();
        $this->logIn('alice@example.com');

        $crawler = $this->client->request('GET', '/teams/'.$teamId.'/enrollment');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]', 'Enrollment');
        self::assertSelectorExists('[data-dialog-trigger-for="create-token"]:not([disabled])');
        self::assertStringContainsString('SHA256:', $crawler->filter('main')->text());
        self::assertSame([], DataTables::draw($this->client, '/teams/'.$teamId.'/enrollment', 'tokens')['data']);

        $form = $this->client->request('GET', '/teams/'.$teamId.'/enrollment/new', server: ['HTTP_TURBO_FRAME' => 'create-token-form'])->filter('form')->form();
        $this->client->submit($form, ['token[kind]' => 'single'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $reveal = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('/dvet_[A-Za-z0-9_-]{43}/', $reveal, $match), 'The token, shown once.');
        $token = $match[0];
        self::assertStringContainsString('AGENT_CONSOLE_PUBKEY: &quot;ed25519:', $reveal);
        self::assertStringContainsString('<turbo-frame id="create-token-form">', $reveal);
        self::assertStringNotContainsString($token, (string) json_encode(AuditLog::entries($this->connection(), 'fleet.')), 'Never audited.');
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'fleet.create_enrollment_token'), 'outcome'));
        $rows = DataTables::draw($this->client, '/teams/'.$teamId.'/enrollment', 'tokens')['data'];
        self::assertCount(1, $rows);
        self::assertStringContainsString('Single use', (string) $rows[0]['kind']);

        $agent = AgentClient::generate();
        $agent->enroll($this->client, $token);
        self::assertResponseStatusCodeSame(201);
        $agentId = $this->fetchString('SELECT id FROM fleet_agent');

        $rows = DataTables::draw($this->client, '/teams/'.$teamId.'/agents', 'agents')['data'];
        self::assertCount(1, $rows);
        self::assertStringContainsString('Waiting for approval', (string) $rows[0]['status']);
        self::assertStringContainsString($agent->key->fingerprint(), (string) $rows[0]['fingerprint']);
        $dialog = $this->client->request('GET', \sprintf('/teams/%s/agents/%s/approve', $teamId, $agentId), server: ['HTTP_TURBO_FRAME' => 'approve-agent-'.$agentId.'-form']);
        self::assertStringContainsString($agent->key->fingerprint(), $dialog->filter('code')->text(), 'The fingerprint to compare.');
        $this->client->submit($dialog->filter('form')->form(), [], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame('approved', $this->fetchString('SELECT status FROM fleet_agent'));

        $hosts = DataTables::draw($this->client, '/teams/'.$teamId.'/hosts', 'hosts')['data'];
        self::assertCount(1, $hosts);
        self::assertStringContainsString('web-01', (string) $hosts[0]['hostname']);

        $menu = DataTables::cell((string) DataTables::draw($this->client, '/teams/'.$teamId.'/agents', 'agents')['data'][0]['actions']);
        $revoke = $menu->filter(\sprintf('form[action="/teams/%s/agents/%s/revoke"]', $teamId, $agentId))->form();
        $this->client->request('POST', $revoke->getUri(), $revoke->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame('revoked', $this->fetchString('SELECT status FROM fleet_agent'));
        $agent->poll($this->client);
        self::assertResponseStatusCodeSame(401);

        $hostId = $this->fetchString('SELECT id FROM fleet_host');
        $delete = DataTables::cell((string) DataTables::draw($this->client, '/teams/'.$teamId.'/hosts', 'hosts')['data'][0]['actions'])->filter('form')->form();
        $this->client->request('POST', $delete->getUri(), $delete->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM fleet_host WHERE id = ?', [$hostId]));
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM fleet_agent'), 'Its revoked agents go with it.');
        $actions = array_unique(array_column(array_filter(AuditLog::entries($this->connection(), 'fleet.'), static fn (array $e): bool => 'success' === $e['outcome']), 'action'));
        sort($actions);
        self::assertSame(['fleet.approve_agent', 'fleet.create_enrollment_token', 'fleet.delete_host', 'fleet.enroll_agent', 'fleet.revoke_agent'], $actions);
        self::assertNotSame('', $project->id()->toString());
    }

    #[Test]
    public function it_should_create_a_reusable_token_into_an_environment_only(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        Seed::project(self::getContainer(), $team, 'Shop');
        $teamId = $team->id()->toString();
        $environmentId = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement('INSERT INTO organization_environment (id, project_id, name, production) SELECT ?, id, ?, true FROM organization_project', [$environmentId, 'production']);
        $this->logIn('alice@example.com');

        $form = $this->client->request('GET', '/teams/'.$teamId.'/enrollment/new', server: ['HTTP_TURBO_FRAME' => 'create-token-form'])->filter('form')->form();
        $this->client->submit($form, ['token[kind]' => 'reusable'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(422, 'A reusable token needs an environment.');
        self::assertStringContainsString('default environment', (string) $this->client->getResponse()->getContent());

        $form = $this->client->request('GET', '/teams/'.$teamId.'/enrollment/new', server: ['HTTP_TURBO_FRAME' => 'create-token-form'])->filter('form')->form();
        $this->client->submit($form, ['token[kind]' => 'reusable', 'token[environment]' => $environmentId, 'token[validity_days]' => 7, 'token[max_uses]' => 3], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame([$environmentId, 3, 'pending'], [$this->fetchString('SELECT environment_id FROM fleet_enrollment_token'), $this->fetchInt('SELECT max_uses FROM fleet_enrollment_token'), $this->fetchString('SELECT status FROM fleet_enrollment_token')]);

        $row = DataTables::draw($this->client, '/teams/'.$teamId.'/enrollment', 'tokens')['data'][0];
        self::assertStringContainsString('Shop / production', (string) $row['environment']);
        $approve = DataTables::cell((string) $row['actions'])->filter('form[action$="/approve"]')->form();
        $this->client->request('POST', $approve->getUri(), $approve->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame('approved', $this->fetchString('SELECT status FROM fleet_enrollment_token'));

        $environments = \sprintf('/teams/%s/projects/%s/environments', $teamId, $this->fetchString('SELECT id FROM organization_project'));
        $delete = DataTables::cell((string) DataTables::draw($this->client, $environments, 'environments')['data'][0]['actions'])->filter('form[action$="/delete"]')->form();
        $this->client->request('POST', $delete->getUri(), $delete->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(422, 'A usable token enrolls into it.');
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM organization_environment'));
    }

    #[Test]
    public function it_should_hide_the_fleet_from_a_guest_and_from_another_team(): void
    {
        $guest = Accounts::create(self::getContainer(), 'guest@example.com');
        $bob = Accounts::create(self::getContainer(), 'bob@example.com');
        $alpha = Seed::team(self::getContainer(), 'Alpha', [[$guest, TeamRole::Guest]]);
        Seed::project(self::getContainer(), $alpha, 'Shop', [[$guest, ProjectRole::Viewer]]);
        $beta = Seed::team(self::getContainer(), 'Beta', [[$bob, TeamRole::Viewer]]);

        $this->logIn('guest@example.com');
        foreach (['hosts', 'agents', 'enrollment'] as $section) {
            $this->client->request('GET', \sprintf('/teams/%s/%s', $alpha->id()->toString(), $section));
            self::assertResponseStatusCodeSame(403, $section);
            $this->client->request('GET', \sprintf('/teams/%s/%s', $beta->id()->toString(), $section));
            self::assertResponseStatusCodeSame(404, $section);
        }

        $this->client->getCookieJar()->clear();
        $this->logIn('bob@example.com');
        $crawler = $this->client->request('GET', '/teams/'.$beta->id()->toString().'/enrollment');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-dialog-trigger-for="create-token"]'), 'A TEAM_VIEWER sees, never enrolls.');
        $this->client->request('GET', '/teams/'.$beta->id()->toString().'/enrollment/new', server: ['HTTP_TURBO_FRAME' => 'create-token-form']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/agents');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_list_every_agent_to_the_super_admin(): void
    {
        Accounts::create(self::getContainer(), 'root@example.com', superAdmin: true);
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        [, $plain] = FleetSeed::token(self::getContainer(), $team->id()->toString());
        AgentClient::generate()->enroll($this->client, $plain);
        $this->logIn('root@example.com');

        $this->client->request('GET', '/admin/agents');
        self::assertResponseIsSuccessful();
        $rows = DataTables::draw($this->client, '/admin/agents', 'agents')['data'];
        self::assertCount(1, $rows);
        self::assertStringContainsString('Alpha', (string) $rows[0]['team']);
    }
}
