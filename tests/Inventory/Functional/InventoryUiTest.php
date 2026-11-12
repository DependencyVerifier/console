<?php

declare(strict_types=1);

namespace Tests\Inventory\Functional;

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
 * The inventory as the team sees it: an application found by the agent, not attached, attached in its dialog, seen in
 * its project, detached; its host, its image and their packages; a TEAM_GUEST sees the applications of its projects
 * and their hosts, nothing else.
 */
final class InventoryUiTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';
    private const string INVENTORY = '019a6b31-5e00-7f12-a3b4-c5d6e7f80920';
    private const string IMAGE = 'sha256:4f1e2d3c4b5a69788796a5b4c3d2e1f00112233445566778899aabbccddeeff0';

    #[Test]
    public function it_should_show_attach_and_detach_an_application(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop');
        $teamId = $team->id()->toString();
        $environmentId = $this->environment($project->id()->toString());
        $agent = $this->inventory($teamId);
        $appId = $this->fetchString('SELECT id FROM inventory_app_instance');
        $hostId = $this->fetchString('SELECT host_id FROM inventory_app_instance');
        $this->logIn('alice@example.com');

        $this->client->request('GET', '/teams/'.$teamId);
        self::assertSelectorExists(\sprintf('turbo-frame#team-inventory[src="/teams/%s/frames/inventory"]', $teamId), 'The overview loads the inventory figures.');
        self::assertSame(['applications' => '1', 'unattached' => '1', 'images' => '1'], $this->counts($teamId));

        $this->client->request('GET', '/teams/'.$teamId.'/applications');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#unattached [data-datatable-name-value="unattached"]', 'The anchor of the Not attached figure.');
        self::assertSelectorTextContains('[data-slot="sidebar"] [data-slot="sidebar-group"] a[aria-current="page"]', 'Applications');
        $unattached = DataTables::draw($this->client, '/teams/'.$teamId.'/applications', 'unattached')['data'];
        self::assertCount(1, $unattached, 'No label, no default environment: not attached.');
        self::assertStringContainsString('shop / php', (string) $unattached[0]['name']);
        self::assertStringContainsString('web-01', (string) $unattached[0]['host']);
        self::assertCount(1, DataTables::draw($this->client, '/teams/'.$teamId.'/applications', 'applications')['data']);

        $page = $this->client->request('GET', \sprintf('/teams/%s/applications/%s', $teamId, $appId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('compose:shop/php', $page->filter('main')->text());
        self::assertCount(1, $page->filter(\sprintf('a[href="/teams/%s/images/%s"]', $teamId, $this->fetchString('SELECT id FROM inventory_container_image'))));
        self::assertCount(0, $page->filter('form[action$="/detach"]'), 'Nothing to detach yet.');
        $packages = DataTables::draw($this->client, \sprintf('/teams/%s/applications/%s', $teamId, $appId), 'packages')['data'];
        self::assertCount(2, $packages);

        $dialog = $this->client->request('GET', \sprintf('/teams/%s/applications/%s/attach', $teamId, $appId), server: ['HTTP_TURBO_FRAME' => 'attach-application-form']);
        self::assertStringContainsString('Shop / production', $dialog->filter('select')->text());
        $this->client->submit($dialog->filter('form')->form(), ['attach[environment]' => $environmentId], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame([$environmentId, 'manual'], [$this->fetchString('SELECT environment_id FROM inventory_app_instance'), $this->fetchString('SELECT assignment FROM inventory_app_instance')]);
        self::assertSame([], DataTables::draw($this->client, '/teams/'.$teamId.'/applications', 'unattached')['data']);

        $projectApps = \sprintf('/teams/%s/projects/%s/applications', $teamId, $project->id()->toString());
        $this->client->request('GET', $projectApps);
        self::assertResponseIsSuccessful();
        $rows = DataTables::draw($this->client, $projectApps, 'applications')['data'];
        self::assertCount(1, $rows);
        self::assertStringContainsString('production', (string) $rows[0]['environment']);

        $detach = $this->client->request('GET', \sprintf('/teams/%s/applications/%s', $teamId, $appId))->filter('form[action$="/detach"]')->form();
        $this->client->request('POST', $detach->getUri(), $detach->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->fetchInt('SELECT COUNT(environment_id) FROM inventory_app_instance'));
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'inventory.attach_application'), 'outcome'));
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'inventory.detach_application'), 'outcome'));

        $host = $this->client->request('GET', \sprintf('/teams/%s/hosts/%s', $teamId, $hostId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('6.1.0-27-amd64', $host->filter('main')->text());
        self::assertCount(1, DataTables::draw($this->client, \sprintf('/teams/%s/hosts/%s', $teamId, $hostId), 'applications')['data']);
        $hostRow = DataTables::draw($this->client, '/teams/'.$teamId.'/hosts', 'hosts')['data'][0];
        self::assertStringContainsString(\sprintf('/teams/%s/hosts/%s', $teamId, $hostId), (string) $hostRow['hostname']);

        $this->send($agent, '019a6b31-5e00-7f12-a3b4-c5d6e7f80921');
        $history = DataTables::draw($this->client, \sprintf('/teams/%s/applications/%s', $teamId, $appId), 'history')['data'];
        self::assertCount(2, $history, 'Two inventories saw it.');
        self::assertStringContainsString('Unchanged', (string) $history[0]['digest'], 'The latest first.');
        self::assertStringContainsString('Changed', (string) $history[1]['digest']);
        self::assertStringContainsString('Scan now', (string) $history[0]['trigger']);
        $hostHistory = DataTables::draw($this->client, \sprintf('/teams/%s/hosts/%s', $teamId, $hostId), 'history')['data'];
        self::assertSame(['Host facts', 'Host facts'], array_map(static fn (array $r): string => trim((string) $r['subject']), $hostHistory), 'No OS packages from a container agent.');

        $imageId = $this->fetchString('SELECT id FROM inventory_container_image');
        $image = $this->client->request('GET', \sprintf('/teams/%s/images/%s', $teamId, $imageId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('shop/php:1.4', $image->filter('h1')->text());
        self::assertCount(1, DataTables::draw($this->client, \sprintf('/teams/%s/images/%s', $teamId, $imageId), 'packages')['data']);
        self::assertCount(1, DataTables::draw($this->client, \sprintf('/teams/%s/images/%s', $teamId, $imageId), 'applications')['data']);
    }

    #[Test]
    public function it_should_show_a_guest_the_applications_of_its_projects_and_their_hosts_only(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $guest = Accounts::create(self::getContainer(), 'guest@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$guest, TeamRole::Guest]]);
        $shop = Seed::project(self::getContainer(), $team, 'Shop', [[$guest, ProjectRole::Viewer]]);
        $blog = Seed::project(self::getContainer(), $team, 'Blog');
        $teamId = $team->id()->toString();
        $this->environment($blog->id()->toString(), 'blog-production');
        $this->inventory($teamId);
        $appId = $this->fetchString('SELECT id FROM inventory_app_instance');
        $hostId = $this->fetchString('SELECT host_id FROM inventory_app_instance');
        $imageId = $this->fetchString('SELECT id FROM inventory_container_image');
        $this->logIn('guest@example.com');

        self::assertSame(['applications' => '0'], $this->counts($teamId), 'Neither the unattached nor the images.');
        $this->client->request('GET', '/teams/'.$teamId.'/applications');
        self::assertResponseIsSuccessful();
        self::assertSame([], DataTables::draw($this->client, '/teams/'.$teamId.'/applications', 'applications')['data'], 'Not attached: in no project of the guest.');
        foreach ([\sprintf('/teams/%s/applications/%s', $teamId, $appId), \sprintf('/teams/%s/hosts/%s', $teamId, $hostId), \sprintf('/teams/%s/images/%s', $teamId, $imageId)] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        $shopEnvironment = $this->environment($shop->id()->toString());
        $this->connection()->executeStatement("UPDATE inventory_app_instance SET environment_id = ?, assignment = 'manual'", [$shopEnvironment]);
        self::assertCount(1, DataTables::draw($this->client, '/teams/'.$teamId.'/applications', 'applications')['data']);
        self::assertSame(['applications' => '1'], $this->counts($teamId));
        $page = $this->client->request('GET', \sprintf('/teams/%s/applications/%s', $teamId, $appId));
        self::assertResponseIsSuccessful();
        self::assertCount(1, DataTables::draw($this->client, \sprintf('/teams/%s/applications/%s', $teamId, $appId), 'history')['data']);
        self::assertCount(0, $page->filter('form[action$="/detach"], [data-dialog-trigger-for="attach-application"]'), 'A viewer never attaches.');
        $this->client->request('GET', \sprintf('/teams/%s/hosts/%s', $teamId, $hostId));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', \sprintf('/teams/%s/applications/%s/attach', $teamId, $appId), server: ['HTTP_TURBO_FRAME' => 'attach-application-form']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/teams/'.$teamId.'/hosts');
        self::assertResponseStatusCodeSame(403, 'The fleet stays hidden.');
    }

    /**
     * The figures of the overview frame, by key.
     *
     * @return array<string, string>
     */
    private function counts(string $teamId): array
    {
        $frame = $this->client->request('GET', \sprintf('/teams/%s/frames/inventory', $teamId), server: ['HTTP_TURBO_FRAME' => 'team-inventory']);
        self::assertResponseIsSuccessful();
        $keys = $frame->filter('[data-inventory-count]')->each(static fn ($n): string => (string) $n->attr('data-inventory-count'));

        return array_combine($keys, $frame->filter('[data-inventory-count] dd')->each(static fn ($n): string => $n->text()));
    }

    private function environment(string $projectId, string $name = 'production'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement('INSERT INTO organization_environment (id, project_id, name, production) VALUES (?, ?, ?, true)', [$id, $projectId, $name]);

        return $id;
    }

    /**
     * An approved agent of the team sends one inventory: host facts, a Compose service and its image.
     */
    private function inventory(string $teamId): AgentClient
    {
        [, $plain] = FleetSeed::token(self::getContainer(), $teamId);
        $agent = AgentClient::generate();
        $agent->enroll($this->client, $plain);
        $manager = FleetSeed::manager(self::getContainer());
        $entity = $manager->find(Agent::class, Uuid::fromString($this->fetchString('SELECT id FROM fleet_agent')));
        self::assertNotNull($entity);
        $entity->approve(Uuid::v7(), new \DateTimeImmutable());
        $manager->flush();
        $this->send($agent, self::INVENTORY);

        return $agent;
    }

    /**
     * The same inventory again under another id: only the missing snapshots are uploaded.
     */
    private function send(AgentClient $agent, string $inventoryId): void
    {
        $facts = Documents::hostFacts();
        $app = Documents::sbom('compose:shop/php', ['pkg:composer/symfony/http-kernel@6.4.3', 'pkg:npm/lodash@4.17.21']);
        $image = Documents::sbom('image:'.self::IMAGE, ['pkg:deb/debian/openssl@3.0.15-1~deb12u1'], 'dpkg');
        $documents = [Documents::digest($facts) => [$facts, 'application/vnd.depverify.host-facts+json;version=1'], Documents::digest($app) => [$app, 'application/vnd.cyclonedx+json;version=1.6'], Documents::digest($image) => [$image, 'application/vnd.cyclonedx+json;version=1.6']];
        $agent->post($this->client, '/agent/v1/inventories', Documents::manifest($inventoryId, $facts, [
            Documents::subject('compose:shop/php', 'compose_service', $app, ['compose_project' => 'shop', 'service' => 'php', 'image_id' => self::IMAGE]),
            Documents::subject('image:'.self::IMAGE, 'container_image', $image, ['image_id' => self::IMAGE, 'provenance' => 'registry', 'base_image' => 'debian:12', 'tags' => ['shop/php:1.4']]),
        ]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertIsArray($answer['missing'] ?? null);
        foreach (array_intersect_key($documents, array_flip(array_filter($answer['missing'], \is_string(...)))) as $digest => [$document, $mediaType]) {
            $agent->putSnapshot($this->client, '/agent/v1/inventories/'.$inventoryId.'/snapshots/'.$digest, $document, $mediaType);
            self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        }

        $agent->post($this->client, '/agent/v1/inventories/'.$inventoryId.'/complete', ['protocol_version' => 1]);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
    }
}
