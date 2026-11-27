<?php

declare(strict_types=1);

namespace Tests\Notification\Functional;

use Organization\Domain\Model\ProjectRole;
use Organization\Domain\Model\TeamRole;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DomCrawler\Form;
use Tests\Organization\Support\Seed;
use Tests\Support\Accounts;
use Tests\Support\AuditLog;
use Tests\Support\DataTables;
use Tests\Support\IdentityWebTestCase;

/**
 * The Notifications section of a team (webhooks, rules of the fleet, one's subscription) and the Notifications tab of
 * a project (rules, recipients, one's subscription), through their dialogs and row menus.
 */
final class NotificationsUiTest extends IdentityWebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    #[Test]
    public function it_should_manage_the_webhooks_and_the_rules_of_the_fleet(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin]]);
        $teamId = $team->id()->toString();
        $page = '/teams/'.$teamId.'/notifications';
        $this->logIn('alice@example.com');

        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-slot="sidebar"] a[href="'.$page.'"]'), 'In the sidebar.');
        self::assertCount(1, $crawler->filter('[data-dialog-trigger-for="create-webhook"]'));
        self::assertSame([], DataTables::draw($this->client, $page, 'webhooks')['data']);

        $form = $this->client->request('GET', $page.'/webhooks/new', server: ['HTTP_TURBO_FRAME' => 'create-webhook-form'])->filter('form')->form();
        $this->client->submit($form, ['webhook[name]' => 'Alerts', 'webhook[url]' => 'http://hooks.test/in'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseStatusCodeSame(422, 'HTTPS only.');
        $form = $this->client->request('GET', $page.'/webhooks/new', server: ['HTTP_TURBO_FRAME' => 'create-webhook-form'])->filter('form')->form();
        $this->client->submit($form, ['webhook[name]' => 'Alerts', 'webhook[url]' => 'https://hooks.test/in'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(1, preg_match('/whsec_[A-Za-z0-9_-]{43}/', (string) $this->client->getResponse()->getContent(), $match), 'The secret, shown once.');
        self::assertStringNotContainsString($match[0], $this->fetchString('SELECT secret FROM notification_webhook'), 'Stored encrypted.');
        self::assertStringNotContainsString($match[0], (string) json_encode(AuditLog::entries($this->connection(), 'notification.')), 'Never audited.');

        $row = DataTables::draw($this->client, $page, 'webhooks')['data'][0];
        self::assertStringContainsString('Never called', (string) $row['last_call']);
        $test = DataTables::cell((string) $row['actions'])->filter('form[action$="/test"]')->form();
        $this->client->request('POST', $test->getUri(), $test->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('HTTP 401', (string) DataTables::draw($this->client, $page, 'webhooks')['data'][0]['last_call'], 'hooks.test knows another secret: refused, and shown.');
        $webhookId = $this->fetchString('SELECT id FROM notification_webhook');

        $rotate = $this->client->request('GET', $page.'/webhooks/'.$webhookId.'/secret', server: ['HTTP_TURBO_FRAME' => 'rotate-webhook-'.$webhookId.'-form'])->filter('form')->form();
        $this->client->submit($rotate, [], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/whsec_[A-Za-z0-9_-]{43}/', (string) $this->client->getResponse()->getContent());

        $form = $this->client->request('GET', $page.'/rules/new', server: ['HTTP_TURBO_FRAME' => 'create-rule-form'])->filter('form')->form();
        self::assertCount(7, $this->client->getCrawler()->filter('input[name="rule[events][]"]'), 'The fleet tells of the agents too.');
        $this->post($form, ['name' => 'Agents', 'events' => ['agent_silent', 'agent_pending'], 'silent_hours' => '12', 'webhooks' => [$webhookId]]);
        self::assertResponseIsSuccessful();
        $rule = DataTables::draw($this->client, $page, 'rules')['data'][0];
        self::assertStringContainsString('Silent agent', (string) $rule['events']);
        self::assertStringContainsString('silent after 12 h', (string) $rule['thresholds']);
        self::assertStringContainsString('Webhook Alerts', (string) $rule['targets']);
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection(), 'notification.define_rule'), 'outcome'));
        $ruleId = $this->fetchString('SELECT id FROM notification_rule');

        $edit = $this->client->request('GET', $page.'/rules/'.$ruleId.'/edit', server: ['HTTP_TURBO_FRAME' => 'edit-rule-'.$ruleId.'-form'])->filter('form')->form();
        self::assertSame('12', $edit->getValues()['rule[silent_hours]'] ?? null);
        $this->post($edit, ['name' => 'Agents of the fleet']);
        self::assertResponseIsSuccessful();
        self::assertSame('Agents of the fleet', $this->fetchString('SELECT name FROM notification_rule'));

        $delete = DataTables::cell((string) DataTables::draw($this->client, $page, 'webhooks')['data'][0]['actions'])->filter('form[action$="/delete"]')->form();
        $this->client->request('POST', $delete->getUri(), $delete->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Webhook Alerts', (string) DataTables::draw($this->client, $page, 'rules')['data'][0]['targets'], 'The rule forgot it.');
        self::assertSame('[]', $this->fetchString('SELECT CAST(webhook_ids AS TEXT) FROM notification_rule'));

        $remove = DataTables::cell((string) DataTables::draw($this->client, $page, 'rules')['data'][0]['actions'])->filter('form[action$="/remove"]')->form();
        $this->client->request('POST', $remove->getUri(), $remove->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM notification_rule'));

        $subscription = $this->client->request('GET', $page)->filter('form[name="subscription"]')->form();
        $this->client->submit($subscription, ['subscription[frequency]' => 'weekly'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame('weekly', $this->fetchString('SELECT frequency FROM notification_subscription WHERE project_id IS NULL'));
        self::assertSame('weekly', $this->client->request('GET', $page)->filter('select[name="subscription[frequency]"] option[selected]')->attr('value'));
    }

    #[Test]
    public function it_should_manage_the_rules_and_the_recipients_of_a_project(): void
    {
        $alice = Accounts::create(self::getContainer(), 'alice@example.com');
        $guest = Accounts::create(self::getContainer(), 'guest@example.com');
        $team = Seed::team(self::getContainer(), 'Alpha', [[$alice, TeamRole::Admin], [$guest, TeamRole::Guest]]);
        $project = Seed::project(self::getContainer(), $team, 'Shop', [[$guest, ProjectRole::Viewer]]);
        $page = \sprintf('/teams/%s/projects/%s/notifications', $team->id()->toString(), $project->id()->toString());
        $this->logIn('alice@example.com');

        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-slot="sidebar"] a[href="'.$page.'"]'), 'A tab of the project.');

        $form = $this->client->request('GET', $page.'/rules/new', server: ['HTTP_TURBO_FRAME' => 'create-rule-form'])->filter('form')->form();
        self::assertCount(5, $this->client->getCrawler()->filter('input[name="rule[events][]"]'), 'A project never tells of the agents.');
        self::assertCount(0, $this->client->getCrawler()->filter('input[name="rule[silent_hours]"]'));
        $this->post($form, ['name' => 'Critical', 'events' => ['vulnerability_opened'], 'min_severity' => 'critical', 'min_epss' => '0.1', 'known_exploited_only' => '1', 'email_subscribers' => '1']);
        self::assertResponseIsSuccessful();
        $rule = DataTables::draw($this->client, $page, 'rules')['data'][0];
        self::assertStringContainsString('Critical or more · EPSS ≥ 10% · Known exploited', (string) $rule['thresholds']);
        self::assertStringContainsString('The subscribers, by email', (string) $rule['targets']);

        $form = $this->client->request('GET', $page.'/recipients/new', server: ['HTTP_TURBO_FRAME' => 'attach-recipient-form'])->filter('form')->form();
        $this->client->submit($form, ['recipient[email]' => 'Ops@Example.com', 'recipient[frequency]' => 'weekly'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(0) ?? throw new \LogicException(), 'To', 'ops@example.com');
        $recipient = DataTables::draw($this->client, $page, 'recipients')['data'][0];
        self::assertStringContainsString('ops@example.com', (string) $recipient['email']);
        self::assertStringContainsString('Waiting for confirmation', (string) $recipient['state']);
        self::assertStringContainsString('Weekly digest', (string) $recipient['frequency']);
        self::assertSame(0, $this->fetchInt("SELECT COUNT(*) FROM identity_user WHERE email = 'ops@example.com'"));

        $cancel = DataTables::cell((string) $recipient['actions'])->filter('form[action$="/cancel"]')->form();
        $this->client->request('POST', $cancel->getUri(), $cancel->getPhpValues(), server: ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame([], DataTables::draw($this->client, $page, 'recipients')['data']);

        $this->client->getCookieJar()->clear();
        $this->logIn('guest@example.com');
        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-dialog-trigger-for="attach-recipient"]'), 'A viewer sees the rules, manages nothing.');
        self::assertCount(0, $crawler->filter('[data-dialog-trigger-for="create-rule"]'));
        self::assertCount(1, DataTables::draw($this->client, $page, 'rules')['data']);
        $subscription = $crawler->filter('form[name="subscription"]')->form();
        $this->client->submit($subscription, ['subscription[frequency]' => 'immediate'], ['HTTP_ACCEPT' => self::STREAM]);
        self::assertResponseIsSuccessful();
        self::assertSame('immediate', $this->fetchString('SELECT frequency FROM notification_subscription'));

        $this->client->request('GET', $page.'/recipients/new', server: ['HTTP_TURBO_FRAME' => 'attach-recipient-form']);
        $form = $this->client->getCrawler()->filter('form');
        if ($form->count() > 0) {
            $this->client->submit($form->form(), ['recipient[email]' => 'x@example.com', 'recipient[frequency]' => 'daily'], ['HTTP_ACCEPT' => self::STREAM]);
        }
        self::assertSame(0, $this->fetchInt('SELECT COUNT(*) FROM notification_recipient_invitation WHERE status = \'pending\''), 'Refused by the command.');
        $this->client->request('GET', '/teams/'.$team->id()->toString().'/notifications');
        self::assertResponseStatusCodeSame(403, 'A guest does not see the fleet.');
    }

    /**
     * A rule form posted with its values (its checkboxes are arrays, which DomCrawler cannot tick at once).
     *
     * @param array<string, mixed> $values
     */
    private function post(Form $form, array $values): void
    {
        $data = $form->getPhpValues();
        \assert(\is_array($data['rule'] ?? null));
        $data['rule'] = [...$data['rule'], ...$values];
        $this->client->request('POST', $form->getUri(), $data, server: ['HTTP_ACCEPT' => self::STREAM]);
    }
}
