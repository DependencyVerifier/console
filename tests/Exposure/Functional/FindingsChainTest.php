<?php

declare(strict_types=1);

namespace Tests\Exposure\Functional;

use Fleet\Domain\Model\Agent;
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
