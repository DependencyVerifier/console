<?php

declare(strict_types=1);

namespace Tests\Exposure\Functional;

use Fleet\Domain\Model\Agent;
use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetSeed;
use Tests\Inventory\Support\Documents;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * From the agent to the findings, the queues run at once (test env) and the public sources are recorded: the packages
 * of an inventory are watched, their advisories and latest releases fetched, the findings of the team computed; an
 * upgrade fixes them.
 */
final class FindingsChainTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';
    private const string IMAGE = 'sha256:4f1e2d3c4b5a69788796a5b4c3d2e1f00112233445566778899aabbccddeeff0';

    #[Test]
    public function it_should_turn_an_inventory_into_findings_and_fix_them_on_upgrade(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $teamId = $team->id()->toString();
        $agent = $this->agent($teamId);

        $this->send($agent, '019a6b31-5e00-7f12-a3b4-c5d6e7f80930', 'pkg:npm/lodash@4.17.20');
        $appId = $this->fetchString('SELECT id FROM inventory_app_instance');
        self::assertSame([
            'application vulnerability DEBIAN-CVE-2023-5678 openssl open',
            'application vulnerability GHSA-35jh-r3h4-6jhm lodash open',
            'application outdated Packagist symfony/http-kernel open',
            'application outdated npm lodash open',
        ], $this->findings(), 'The image counts for the application; GHSA-h7vf spares http-kernel 6.4.3.');
        self::assertSame([$appId, '4.17.21', 'high', '4.18.1'], [
            $this->fetchString("SELECT subject_id FROM exposure_finding WHERE finding_key = 'GHSA-35jh-r3h4-6jhm lodash'"),
            $this->fetchString("SELECT fixed FROM exposure_finding WHERE finding_key = 'GHSA-35jh-r3h4-6jhm lodash'"),
            $this->fetchString("SELECT severity FROM exposure_finding WHERE finding_key = 'GHSA-35jh-r3h4-6jhm lodash'"),
            $this->fetchString("SELECT latest FROM exposure_finding WHERE finding_key = 'npm lodash'"),
        ]);
        self::assertNotEmpty(AuditLog::entries($this->connection(), 'exposure.refresh_findings'));
        self::assertSame([], AuditLog::entries($this->connection(), 'exposure.request_refresh'), 'A request writes no entry.');

        $this->send($agent, '019a6b31-5e00-7f12-a3b4-c5d6e7f80931', 'pkg:npm/lodash@4.18.1');
        self::assertSame([
            'application vulnerability DEBIAN-CVE-2023-5678 openssl open',
            'application vulnerability GHSA-35jh-r3h4-6jhm lodash fixed',
            'application outdated Packagist symfony/http-kernel open',
            'application outdated npm lodash fixed',
        ], $this->findings(), 'Upgraded: fixed, never deleted.');
    }

    #[Test]
    public function it_should_show_the_findings_and_accept_then_revoke_a_risk(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $guest = Accounts::create(self::getContainer(), 'guest@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$guest, TeamRole::Guest]]);
        $teamId = $team->id()->toString();
        $this->send($this->agent($teamId), '019a6b31-5e00-7f12-a3b4-c5d6e7f80932', 'pkg:npm/lodash@4.17.20');
        $appId = $this->fetchString('SELECT id FROM inventory_app_instance');
        $findingId = $this->fetchString("SELECT id FROM exposure_finding WHERE finding_key = 'GHSA-35jh-r3h4-6jhm lodash'");
        $this->logIn('alice@example.com');

        $appUrl = \sprintf('/teams/%s/applications/%s', $teamId, $appId);
        $this->client->request('GET', $appUrl);
        self::assertResponseIsSuccessful();
        $rows = DataTables::draw($this->client, $appUrl, 'findings')['data'];
        self::assertCount(4, $rows);
        self::assertStringContainsString('Command Injection in lodash', (string) $rows[0]['title'], 'Vulnerabilities first, the most severe first (high before medium).');
        self::assertStringContainsString('4.17.20 → 4.17.21', (string) $rows[0]['versions']);
        self::assertStringContainsString('Open', (string) $rows[0]['status']);

        $findingUrl = \sprintf('/teams/%s/findings/%s', $teamId, $findingId);
        $page = $this->client->request('GET', $findingUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Command Injection in lodash');
        self::assertCount(1, $page->filter('a[href="https://osv.dev/vulnerability/CVE-2021-23337"]'));
        self::assertSelectorExists('turbo-frame#accept-risk-form');

        $frame = ['HTTP_TURBO_FRAME' => 'accept-risk-form'];
        $acceptUrl = $findingUrl.'/accept';
        $form = $this->client->request('GET', $acceptUrl, server: $frame)->filter('form')->form(['accept[justification]' => 'Only reachable from the VPN.', 'accept[expiresOn]' => '2020-01-01']);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        self::assertResponseStatusCodeSame(422, 'A past date.');
        $form = $this->client->request('GET', $acceptUrl, server: $frame)->filter('form')->form(['accept[justification]' => 'Only reachable from the VPN.', 'accept[expiresOn]' => new \DateTimeImmutable('+30 days')->format('Y-m-d')]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM, ...$frame]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Accepted until', (string) DataTables::draw($this->client, $appUrl, 'findings')['data'][0]['status']);
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'exposure.accept_risk'), 'outcome'));

        $page = $this->client->request('GET', $findingUrl);
        self::assertSelectorTextContains('[data-acceptance="active"]', 'Only reachable from the VPN.');
        $revoke = $page->filter('form[action$="/revoke"]')->form();
        $this->client->request('POST', $revoke->getUri(), $revoke->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Open', (string) DataTables::draw($this->client, $appUrl, 'findings')['data'][0]['status']);
        $this->client->request('GET', $findingUrl);
        self::assertSelectorExists('[data-acceptance="inactive"]');
        self::assertSelectorNotExists('form[action$="/revoke"]', 'Nothing running to revoke.');

        $this->client->getCookieJar()->clear();
        $this->logIn('guest@example.com');
        $this->client->request('GET', $findingUrl);
        self::assertResponseStatusCodeSame(404, 'Not attached: in no project of the guest.');
        $this->client->request('GET', $acceptUrl, server: $frame);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_show_the_dashboards_of_the_team_the_project_the_environment_and_the_host(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $guest = Accounts::create(self::getContainer(), 'guest@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$guest, TeamRole::Guest]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop', [[$guest, ProjectRole::Viewer]]);
        $teamId = $team->id()->toString();
        $projectId = $project->id()->toString();
        $environmentId = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement('INSERT INTO organization_environment (id, project_id, name, production) VALUES (?, ?, ?, true)', [$environmentId, $projectId, 'production']);
        $this->send($this->agent($teamId), '019a6b31-5e00-7f12-a3b4-c5d6e7f80933', 'pkg:npm/lodash@4.17.20');
        $appId = $this->fetchString('SELECT id FROM inventory_app_instance');
        $hostId = $this->fetchString('SELECT host_id FROM inventory_app_instance');
        self::assertSame(['shop / php', null], [$this->fetchString('SELECT DISTINCT subject_name FROM exposure_finding'), $this->connection()->fetchOne('SELECT MAX(CAST(project_id AS TEXT)) FROM exposure_finding')], 'Named, not attached yet.');
        $this->logIn('alice@example.com');

        $page = $this->client->request('GET', '/teams/'.$teamId.'/findings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]', 'Findings');
        self::assertSame(['0', '1', '1', '0', '0'], [$page->filter('[data-figure="critical"] dd')->text(), $page->filter('[data-figure="high"] dd')->text(), $page->filter('[data-figure="medium"] dd')->text(), $page->filter('[data-figure="low"] dd')->text(), $page->filter('[data-figure="kev"] dd')->text()]);
        self::assertSame('shop / php', $page->filter('[data-dashboard="top"] a')->text());
        $chart = $page->filter('[data-controller="chart-theme"] canvas')->first();
        self::assertStringContainsString('var(--sev-high)', (string) $chart->attr('data-symfony--ux-chartjs--chart-view-value'), 'Colours from the theme, resolved in the browser.');
        self::assertSame(4, \count(DataTables::draw($this->client, '/teams/'.$teamId.'/findings', 'findings')['data']));
        $outdated = DataTables::draw($this->client, '/teams/'.$teamId.'/findings?kind=outdated', 'findings')['data'];
        self::assertCount(2, $outdated, 'Filtered from the query string.');
        self::assertStringContainsString('shop / php', (string) $outdated[0]['subject']);
        self::assertSame([], DataTables::draw($this->client, '/teams/'.$teamId.'/findings?status=fixed', 'findings')['data']);
        $this->client->request('GET', '/teams/'.$teamId.'/frames/exposure', server: ['HTTP_TURBO_FRAME' => 'team-exposure']);
        self::assertSelectorTextContains('[data-figure="high"] dd', '1');

        $dialog = $this->client->request('GET', \sprintf('/teams/%s/applications/%s/attach', $teamId, $appId), server: ['HTTP_TURBO_FRAME' => 'attach-application-form']);
        $this->client->submit($dialog->filter('form')->form(), ['attach[environment]' => $environmentId], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame([$projectId, $environmentId], [$this->fetchString('SELECT DISTINCT CAST(project_id AS TEXT) FROM exposure_finding'), $this->fetchString('SELECT DISTINCT CAST(environment_id AS TEXT) FROM exposure_finding')], 'Placed again once attached.');

        $projectUrl = \sprintf('/teams/%s/projects/%s/findings', $teamId, $projectId);
        $this->client->request('GET', $projectUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-figure="high"] dd', '1');
        self::assertCount(4, DataTables::draw($this->client, $projectUrl, 'findings')['data']);
        $this->client->request('GET', \sprintf('/teams/%s/projects/%s/frames/exposure', $teamId, $projectId), server: ['HTTP_TURBO_FRAME' => 'project-exposure']);
        self::assertSelectorExists(\sprintf('a[href="%s"]', $projectUrl));

        $environmentUrl = \sprintf('/teams/%s/projects/%s/environments/%s', $teamId, $projectId, $environmentId);
        $environments = $this->client->request('GET', \sprintf('/teams/%s/projects/%s/environments', $teamId, $projectId));
        self::assertCount(1, DataTables::draw($this->client, \sprintf('/teams/%s/projects/%s/environments', $teamId, $projectId), 'environments')['data']);
        self::assertStringContainsString($environmentUrl, (string) DataTables::draw($this->client, \sprintf('/teams/%s/projects/%s/environments', $teamId, $projectId), 'environments')['data'][0]['name']);
        $this->client->request('GET', $environmentUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2[data-environment]', 'production');
        self::assertCount(1, DataTables::draw($this->client, $environmentUrl, 'applications')['data']);
        self::assertCount(2, DataTables::draw($this->client, $environmentUrl.'?kind=vulnerability', 'findings')['data']);
        $this->client->request('GET', \sprintf('/teams/%s/projects/%s/environments/%s', $teamId, $projectId, Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', \sprintf('/teams/%s/hosts/%s/frames/exposure', $teamId, $hostId), server: ['HTTP_TURBO_FRAME' => 'host-exposure']);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->logIn('guest@example.com');
        $this->client->request('GET', '/teams/'.$teamId.'/findings');
        self::assertResponseIsSuccessful();
        self::assertCount(4, DataTables::draw($this->client, '/teams/'.$teamId.'/findings', 'findings')['data'], 'The findings of its project.');
        $this->client->request('GET', $environmentUrl);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', \sprintf('/teams/%s/hosts/%s/frames/exposure', $teamId, $hostId), server: ['HTTP_TURBO_FRAME' => 'host-exposure']);
        self::assertStringNotContainsString('data-figure', (string) $this->client->getResponse()->getContent(), 'The fleet stays hidden.');
    }

    /**
     * @return list<string>
     */
    private function findings(): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn("SELECT subject_type || ' ' || kind || ' ' || finding_key || ' ' || status FROM exposure_finding ORDER BY kind DESC, finding_key");
    }

    private function agent(string $teamId): AgentClient
    {
        [, $plain] = FleetSeed::token(self::getContainer(), $teamId);
        $agent = AgentClient::generate();
        $agent->enroll($this->client, $plain);
        $manager = FleetSeed::manager(self::getContainer());
        $entity = $manager->find(Agent::class, Uuid::fromString($this->fetchString('SELECT id FROM fleet_agent')));
        self::assertNotNull($entity);
        $entity->approve(Uuid::v7(), new \DateTimeImmutable());
        $manager->flush();

        return $agent;
    }

    /**
     * A Compose service with http-kernel 6.4.3 and the given lodash, its image with OpenSSL of Debian 12.
     */
    private function send(AgentClient $agent, string $inventoryId, string $lodash): void
    {
        $facts = Documents::hostFacts();
        $app = Documents::sbom('compose:shop/php', ['pkg:composer/symfony/http-kernel@6.4.3', $lodash]);
        $image = Documents::sbom('image:'.self::IMAGE, ['pkg:deb/debian/libssl3@3.0.11-1~deb12u2?arch=amd64&distro=debian-12&upstream=openssl'], 'dpkg');
        $documents = [Documents::digest($facts) => [$facts, 'application/vnd.depverify.host-facts+json;version=1'], Documents::digest($app) => [$app, 'application/vnd.cyclonedx+json;version=1.6'], Documents::digest($image) => [$image, 'application/vnd.cyclonedx+json;version=1.6']];
        $agent->post($this->client, '/agent/v1/inventories', Documents::manifest($inventoryId, $facts, [
            Documents::subject('compose:shop/php', 'compose_service', $app, ['compose_project' => 'shop', 'service' => 'php', 'image_id' => self::IMAGE]),
            Documents::subject('image:'.self::IMAGE, 'container_image', $image, ['image_id' => self::IMAGE, 'provenance' => 'registry', 'tags' => ['shop/php:1.4']]),
        ]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertIsArray($answer['missing'] ?? null);
        foreach (array_intersect_key($documents, array_flip(array_filter($answer['missing'], \is_string(...)))) as $digest => [$document, $mediaType]) {
            $agent->putSnapshot($this->client, '/agent/v1/inventories/'.$inventoryId.'/snapshots/'.$digest, $document, $mediaType);
        }

        $agent->post($this->client, '/agent/v1/inventories/'.$inventoryId.'/complete', ['protocol_version' => 1]);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
    }
}
