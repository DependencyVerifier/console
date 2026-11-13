<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Application\Command\Webhook\DeleteWebhook;
use Notification\Application\Command\Webhook\EditWebhook;
use Notification\Application\Command\Webhook\Handler\DeleteWebhookHandler;
use Notification\Application\Command\Webhook\Handler\EditWebhookHandler;
use Notification\Application\Command\Webhook\Handler\RegisterWebhookHandler;
use Notification\Application\Command\Webhook\Handler\RotateWebhookSecretHandler;
use Notification\Application\Command\Webhook\Handler\TestWebhookHandler;
use Notification\Application\Command\Webhook\Handler\WebhookOfTeam;
use Notification\Application\Command\Webhook\RegisterWebhook;
use Notification\Application\Command\Webhook\RotateWebhookSecret;
use Notification\Application\Command\Webhook\TestWebhook;
use Notification\Application\Security\NotificationAccess;
use Notification\Application\Webhook\WebhookRequest;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\RuleThresholds;
use Notification\Domain\Model\WebhookChannel;
use Notification\Domain\Model\WebhookSecretBinding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\GrantedPermissions;
use Tests\Notification\Support\BoundSecretBox;
use Tests\Notification\Support\InMemoryRules;
use Tests\Notification\Support\InMemoryWebhooks;
use Tests\Notification\Support\ScriptedWebhookCaller;
use Tests\Organization\Support\FixedQueries;

/**
 * The webhooks of a team (`team.channels.manage`): registered with a secret shown once and stored encrypted, edited,
 * given a new secret, tested, deleted (the rules forget it); and who sees, manages and subscribes to what.
 */
final class WebhooksTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string OTHER_TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f02';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string WEBHOOK = '019a6b2c-1f00-7a11-8b22-3c44d55e6f31';

    private InMemoryWebhooks $webhooks;
    private InMemoryRules $rules;
    private GrantedPermissions $permissions;
    private ScriptedWebhookCaller $caller;
    private MockClock $clock;
    private ?string $role = 'team_member';
    private bool $superAdmin = false;
    /** @var list<string> */
    private array $visible = [self::PROJECT];

    protected function setUp(): void
    {
        $this->webhooks = new InMemoryWebhooks();
        $this->rules = new InMemoryRules();
        $this->permissions = new GrantedPermissions();
        $this->permissions->team[self::TEAM] = [Permission::TeamChannelsManage];
        $this->caller = new ScriptedWebhookCaller();
        $this->clock = new MockClock('2026-11-13 09:00:00');
    }

    #[Test]
    public function it_should_register_a_webhook_with_its_secret_encrypted(): void
    {
        $this->register();

        $webhook = $this->webhooks->webhooks[0];
        self::assertSame([self::WEBHOOK, self::TEAM, 'Alerts', 'https://hooks.example.com/in'], [$webhook->id()->toRfc4122(), $webhook->teamId()->toRfc4122(), $webhook->name(), $webhook->url()]);
        self::assertSame(new BoundSecretBox()->encrypt('whsec_1', WebhookSecretBinding::for(Uuid::fromString(self::WEBHOOK))), $webhook->secret());
        self::assertEquals($this->clock->now(), $webhook->createdAt());

        $command = new RegisterWebhook(self::WEBHOOK, self::TEAM, 'Alerts', 'https://hooks.example.com/in', 'whsec_1');
        self::assertSame(['notification.register_webhook', 'team', self::TEAM, '{"webhook_id":"'.self::WEBHOOK.'"}'], [$command->auditAction()->name, $command->auditTarget()->type, $command->auditTarget()->id(), $command->auditContext()->toJson()]);
    }

    #[Test]
    public function it_should_refuse_a_member_without_the_permission_or_a_guest(): void
    {
        $this->permissions->team[self::TEAM] = [];
        $this->assertThrows(PermissionDenied::class, fn () => $this->register());
        try {
            $this->access()->assertManages(self::TEAM, null);
            self::fail('403');
        } catch (PermissionDenied $denied) {
            self::assertSame('Permission "team.channels.manage" required.', $denied->getMessage());
        }
        try {
            $this->access()->assertManages(self::TEAM, self::PROJECT);
            self::fail('403');
        } catch (PermissionDenied $denied) {
            self::assertSame('Permission "project.notifications.manage" required.', $denied->getMessage());
        }

        $this->permissions->team[self::TEAM] = [Permission::TeamChannelsManage];
        $this->role = 'team_guest';
        $this->assertThrows(PermissionDenied::class, fn () => $this->register(), 'A guest does not see the fleet.');

        self::assertSame([], $this->webhooks->webhooks);
    }

    #[Test]
    public function it_should_edit_rotate_and_test_a_webhook_of_the_team(): void
    {
        $this->register();
        $webhook = $this->webhooks->webhooks[0];
        $of = new WebhookOfTeam($this->webhooks, $this->access());

        new EditWebhookHandler($of)(new EditWebhook(self::WEBHOOK, 'Pager', 'https://pager.example.com'));
        self::assertSame(['Pager', 'https://pager.example.com'], [$webhook->name(), $webhook->url()]);

        new RotateWebhookSecretHandler($of, new BoundSecretBox())(new RotateWebhookSecret(self::WEBHOOK, 'whsec_2'));
        self::assertSame(new BoundSecretBox()->encrypt('whsec_2', WebhookSecretBinding::for($webhook->id())), $webhook->secret());

        $this->caller->failure = 'HTTP 500';
        new TestWebhookHandler($of, new BoundSecretBox(), $this->caller, $this->clock)(new TestWebhook(self::WEBHOOK));
        self::assertFalse($webhook->lastSucceeded());
        self::assertSame('HTTP 500', $webhook->lastError());
        self::assertEquals($this->clock->now(), $webhook->lastCalledAt());
        $request = $this->caller->requests[0];
        self::assertSame('https://pager.example.com', $request->url);
        $body = json_decode($request->body, true);
        self::assertIsArray($body);
        self::assertSame([1, 'test', self::TEAM, null, [], '2026-11-13T09:00:00+00:00'], [$body['version'], $body['event'], $body['team_id'], $body['project_id'], $body['data'], $body['occurred_at']]);
        self::assertSame($body['id'], $body['delivery']);
        self::assertSame($request->headers['X-DepVerify-Delivery'], $body['id']);
        self::assertStringContainsString('"data":{}', $request->body, 'An object, never a list.');
        self::assertSame(WebhookRequest::signature('whsec_2', '1794560400', $request->body), $request->headers['X-DepVerify-Signature'], 'Signed with the new secret.');

        $this->caller->failure = null;
        new TestWebhookHandler($of, new BoundSecretBox(), $this->caller, $this->clock)(new TestWebhook(self::WEBHOOK));
        self::assertTrue($webhook->lastSucceeded());

        foreach ([new EditWebhook(self::WEBHOOK, 'n', 'https://x.example.com'), new RotateWebhookSecret(self::WEBHOOK, 's'), new TestWebhook(self::WEBHOOK), new DeleteWebhook(self::WEBHOOK)] as $command) {
            self::assertSame(['notification_webhook', self::WEBHOOK, '{}'], [$command->auditTarget()->type, $command->auditTarget()->id(), $command->auditContext()->toJson()]);
        }
        self::assertSame(['notification.edit_webhook', 'notification.rotate_webhook_secret', 'notification.test_webhook', 'notification.delete_webhook'], [new EditWebhook('w', 'n', 'u')->auditAction()->name, new RotateWebhookSecret('w', 's')->auditAction()->name, new TestWebhook('w')->auditAction()->name, new DeleteWebhook('w')->auditAction()->name]);
    }

    #[Test]
    public function it_should_not_find_a_webhook_out_of_reach(): void
    {
        $foreign = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::OTHER_TEAM), 'Foreign', 'https://foreign.example.com', 's', $this->clock->now());
        $this->webhooks->add($foreign);
        $of = new WebhookOfTeam($this->webhooks, $this->access());

        foreach ([Uuid::v7()->toRfc4122(), 'nope'] as $id) {
            $this->assertThrows(ResourceNotFound::class, static fn () => $of->get($id));
        }

        $this->assertThrows(PermissionDenied::class, static fn () => $of->get($foreign->id()->toRfc4122()), 'Its own team decides.');
    }

    #[Test]
    public function it_should_delete_a_webhook_that_the_rules_forget(): void
    {
        $this->register();
        $kept = WebhookChannel::register(Uuid::v7(), Uuid::fromString(self::TEAM), 'Kept', 'https://kept.example.com', 's', $this->clock->now());
        $this->webhooks->add($kept);
        $rule = NotificationRule::define(Uuid::v7(), Uuid::fromString(self::TEAM), null, null, 'Fleet', [NotificationEvent::AgentPending], new RuleThresholds(), [Uuid::fromString(self::WEBHOOK), $kept->id()], false, $this->clock->now());
        $this->rules->add($rule);

        new DeleteWebhookHandler(new WebhookOfTeam($this->webhooks, $this->access()), $this->webhooks, $this->rules)(new DeleteWebhook(self::WEBHOOK));

        self::assertSame([$kept], $this->webhooks->webhooks);
        self::assertSame([$kept->id()->toRfc4122()], $rule->webhookIds());
    }

    #[Test]
    public function it_should_tell_who_sees_manages_and_subscribes(): void
    {
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsSubscribe];
        $access = $this->access();
        $team = $access->assertSees(self::TEAM, null);
        self::assertSame(self::TEAM, $team->teamId);
        self::assertSame(self::TEAM, $access->assertSees(self::TEAM, self::PROJECT)->teamId);
        $this->assertThrows(ResourceNotFound::class, static fn () => $access->assertSees(self::TEAM, Uuid::v7()->toRfc4122()));

        self::assertFalse($access->manages(self::TEAM, null));
        self::assertFalse($access->manages(self::TEAM, self::PROJECT));
        $this->assertThrows(PermissionDenied::class, static fn () => $access->assertManages(self::TEAM, self::PROJECT));
        $access->assertSubscribes(self::TEAM, self::PROJECT);
        $access->assertSubscribes(self::TEAM, null);
        self::assertTrue($access->subscribes($team, null), 'A member who sees the fleet.');

        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage, Permission::TeamChannelsManage];
        self::assertTrue($access->manages(self::TEAM, self::PROJECT));
        self::assertTrue($access->manages(self::TEAM, null));
        $access->assertManages(self::TEAM, self::PROJECT);
        $this->assertThrows(PermissionDenied::class, static fn () => $access->assertSubscribes(self::TEAM, self::PROJECT), 'Subscribing is its own permission.');

        $this->role = null;
        $this->superAdmin = true;
        $outside = $this->access()->assertSees(self::TEAM, null);
        self::assertFalse($this->access()->subscribes($outside, null), 'A super admin outside the team receives nothing.');
        $this->assertThrows(PermissionDenied::class, fn () => $this->access()->assertSubscribes(self::TEAM, null));

        $this->role = 'team_guest';
        $this->superAdmin = false;
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsSubscribe];
        $guest = $this->access();
        self::assertFalse($guest->subscribes(new TeamAccess(self::TEAM, 'Platform', 'team_guest', false), null));
        self::assertTrue($guest->subscribes(new TeamAccess(self::TEAM, 'Platform', 'team_guest', false), self::PROJECT));
    }

    private function register(): void
    {
        new RegisterWebhookHandler($this->access(), $this->webhooks, new BoundSecretBox(), $this->clock)(new RegisterWebhook(self::WEBHOOK, self::TEAM, 'Alerts', 'https://hooks.example.com/in', 'whsec_1'));
    }

    private function access(): NotificationAccess
    {
        return new NotificationAccess(new FixedQueries([
            GetTeamAccess::class => fn (object $q): TeamAccess => new TeamAccess(self::TEAM, 'Platform', $this->role, $this->superAdmin),
            GetVisibleProjects::class => fn (object $q): VisibleProjects => new VisibleProjects(false, $this->visible),
        ]), $this->permissions);
    }

    /**
     * @param class-string<\Throwable> $expected
     * @param \Closure(): mixed        $gesture
     */
    private function assertThrows(string $expected, \Closure $gesture, string $message = ''): void
    {
        try {
            $gesture();
            self::fail($message ?: $expected);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($expected, $thrown, $message);
        }
    }
}
