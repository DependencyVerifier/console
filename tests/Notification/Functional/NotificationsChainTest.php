<?php

declare(strict_types=1);

namespace Tests\Notification\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Fleet\Domain\Model\Agent;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Model\WebhookSecretBinding;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Crypto\SecretBox;
use Shared\Application\Crypto\TokenHasher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AgentClient;
use Tests\Fleet\Support\FleetSeed;
use Tests\Inventory\Support\Documents;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\IdentityWebTestCase;
use Tests\Vulnerability\Support\RecordedFeeds;

use function Symfony\Component\String\u;

/**
 * From an inventory to the notifications, the queues run at once (test env): a vulnerability opened is emailed at once
 * to a subscriber and posted, signed, to a webhook (hooks.test checks the signature); told once; a digest gathers the
 * notices of a day; the unsubscribe link of an email turns the subscription off.
 */
final class NotificationsChainTest extends IdentityWebTestCase
{
    private const string IMAGE = 'sha256:4f1e2d3c4b5a69788796a5b4c3d2e1f00112233445566778899aabbccddeeff0';

    #[Test]
    public function it_should_email_and_post_a_vulnerability_once(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $guest = Accounts::create(self::getContainer(), 'guest@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$guest, TeamRole::Guest]]);
        $teamId = $team->id()->toString();
        $webhook = $this->webhook($teamId, 'https://hooks.test/in');
        $broken = $this->webhook($teamId, 'https://hooks.test/broken', 'not the secret');
        $this->rule($teamId, [NotificationEvent::VulnerabilityOpened], [$webhook, $broken]);
        $this->subscribe($teamId, $alice->id()->toString(), Frequency::Immediate);
        $this->subscribe($teamId, $guest->id()->toString(), Frequency::Immediate);
        $agent = $this->agent($teamId);

        $this->send($agent, '019a6b31-5e00-7f12-a3b4-c5d6e7f80950', 'pkg:npm/lodash@4.17.20');

        self::assertEmailCount(2, message: 'Two vulnerabilities opened, to Alice only: a guest does not see the fleet.');
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');
        self::assertEmailHeaderSame($email, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        self::assertSame([
            'email sent immediate 1',
            'email sent immediate 1',
            'webhook pending immediate 1',
            'webhook pending immediate 1',
            'webhook sent immediate 1',
            'webhook sent immediate 1',
        ], $this->deliveries(), 'The broken receiver refuses the signature: attempted again later.');
        self::assertSame(['broken: false', 'in: true'], $this->connection()->fetchFirstColumn("SELECT CONCAT(name, ': ', CASE WHEN last_succeeded THEN 'true' ELSE 'false' END) FROM notification_webhook ORDER BY name"));

        $this->send($agent, '019a6b31-5e00-7f12-a3b4-c5d6e7f80951', 'pkg:npm/lodash@4.17.20');
        self::assertEmailCount(0, message: 'Nothing changed: nothing told.');
        self::assertCount(6, $this->deliveries());

        $this->send($agent, '019a6b31-5e00-7f12-a3b4-c5d6e7f80952', 'pkg:npm/lodash@4.18.1');
        self::assertEmailCount(0, message: 'Fixed: not an event of the rule.');
    }

    #[Test]
    public function it_should_gather_a_digest_then_unsubscribe_from_its_link(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $teamId = $team->id()->toString();
        $this->rule($teamId, [NotificationEvent::VulnerabilityOpened, NotificationEvent::Outdated], []);
        $subscription = $this->subscribe($teamId, $alice->id()->toString(), Frequency::Daily);
        $this->send($this->agent($teamId), '019a6b31-5e00-7f12-a3b4-c5d6e7f80953', 'pkg:npm/lodash@4.17.20');
        self::assertEmailCount(0, message: 'A daily subscriber waits for the digest.');
        self::assertSame(['email pending daily 0', 'email pending daily 0', 'email pending daily 0', 'email pending daily 0'], $this->deliveries());

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:notification:digest'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 digests queued.', $tester->getDisplay());
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, 'Daily digest: 4 notifications');
        self::assertSame(['email sent daily 1', 'email sent daily 1', 'email sent daily 1', 'email sent daily 1'], $this->deliveries());

        $link = (string) new Crawler((string) $email->getHtmlBody())->filter('a[href*="/notifications/unsubscribe?"]')->first()->attr('href');
        self::assertStringStartsWith('https://', $link);

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSame('daily', $this->frequency($subscription), 'Opening the link changes nothing.');

        $this->client->request('POST', u($link)->replaceMatches('/_hash=[^&]+/', '_hash=altered')->toString());
        self::assertResponseStatusCodeSame(404, 'An altered link.');
        $this->client->request('POST', u($link)->replace($subscription, Uuid::v7()->toRfc4122())->toString());
        self::assertResponseStatusCodeSame(404, 'Another subscription, the same signature.');
        self::assertSame('daily', $this->frequency($subscription));

        $this->client->request('POST', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'sends no more email');
        self::assertSame('off', $this->frequency($subscription));
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'notification.unsubscribe'), 'outcome'));

        $tester->execute([]);
        self::assertStringContainsString('0 digests queued.', $tester->getDisplay());
    }

    #[Test]
    public function it_should_confirm_a_recipient_from_its_link_only_with_a_post(): void
    {
        $team = Seed::team(self::getContainer(), 'Alpha', []);
        $token = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';
        $hasher = self::getContainer()->get(TokenHasher::class);
        $subscription = Subscription::attach(Uuid::v7(), Uuid::fromString($team->id()->toString()), Uuid::v7(), Uuid::v7(), Frequency::Daily, Uuid::v7(), $token, $hasher->hash($token), new \DateTimeImmutable());
        $manager = $this->manager();
        $manager->persist($subscription);
        $manager->flush();
        $id = $subscription->id()->toRfc4122();

        $this->client->request('GET', '/notifications/confirm/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[method="post"] button[type="submit"]');
        self::assertNull($this->connection()->fetchOne('SELECT confirmed_at FROM notification_subscription WHERE id = ?', [$id]), 'Opening the link confirms nothing.');

        $this->client->request('POST', '/notifications/confirm/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Confirmed');
        self::assertNotNull($this->connection()->fetchOne('SELECT confirmed_at FROM notification_subscription WHERE id = ?', [$id]));
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'notification.confirm_recipient'), 'outcome'));

        $this->client->request('POST', '/notifications/confirm/'.$token);
        self::assertResponseStatusCodeSame(422, 'Spent.');
        self::assertSelectorTextContains('main', 'expired or was already used');
        $this->client->request('GET', '/notifications/confirm/short');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function it_should_queue_the_deliveries_due_again(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:notification:check'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Checked; 0 deliveries queued again.', $tester->getDisplay());
    }

    /**
     * @return list<string> channel, status, frequency, attempts of every delivery
     */
    private function deliveries(): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn("SELECT CONCAT(channel, ' ', status, ' ', frequency, ' ', attempts) FROM notification_delivery ORDER BY channel, status, id");
    }

    private function frequency(string $subscription): string
    {
        return $this->fetchString('SELECT frequency FROM notification_subscription WHERE id = ?', [$subscription]);
    }

    private function webhook(string $teamId, string $url, string $secret = RecordedFeeds::WEBHOOK_SECRET): Uuid
    {
        $id = Uuid::v7();
        $box = self::getContainer()->get(SecretBox::class);
        $manager = $this->manager();
        $manager->persist(WebhookChannel::register($id, Uuid::fromString($teamId), u($url)->afterLast('/')->toString(), $url, $box->encrypt($secret, WebhookSecretBinding::for($id)), new \DateTimeImmutable()));
        $manager->flush();

        return $id;
    }

    /**
     * @param list<NotificationEvent> $events
     * @param list<Uuid>              $webhooks
     */
    private function rule(string $teamId, array $events, array $webhooks): void
    {
        $manager = $this->manager();
        $manager->persist(NotificationRule::define(Uuid::v7(), Uuid::fromString($teamId), null, null, 'Fleet', $events, new RuleThresholds(), $webhooks, true, new \DateTimeImmutable()));
        $manager->flush();
    }

    private function subscribe(string $teamId, string $userId, Frequency $frequency): string
    {
        $subscription = Subscription::subscribe(Uuid::v7(), Uuid::fromString($teamId), null, Uuid::fromString($userId), $frequency, new \DateTimeImmutable());
        $manager = $this->manager();
        $manager->persist($subscription);
        $manager->flush();

        return $subscription->id()->toRfc4122();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine')->getManagerForClass(NotificationRule::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
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
