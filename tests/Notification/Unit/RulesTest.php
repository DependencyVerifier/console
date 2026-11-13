<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Application\Command\Rule\DefineRule;
use Notification\Application\Command\Rule\Handler\DefineRuleHandler;
use Notification\Application\Command\Rule\Handler\RedefineRuleHandler;
use Notification\Application\Command\Rule\Handler\RuleInput;
use Notification\Application\Command\Rule\Handler\RuleOfScope;
use Notification\Application\Command\Rule\RedefineRule;
use Notification\Application\Command\Rule\RuleSettings;
use Notification\Application\Command\Subscription\Handler\SubscribeHandler;
use Notification\Application\Command\Subscription\Subscribe;
use Notification\Application\Query\GetNotifications;
use Notification\Application\Query\Handler\GetNotificationsHandler;
use Notification\Application\Query\Handler\ListRulesHandler;
use Notification\Application\Query\ListRules;
use Notification\Application\Query\View\NotificationsView;
use Notification\Application\Query\View\Option;
use Notification\Application\Security\NotificationAccess;
use Notification\Domain\Model\Frequency;
use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\Subscription;
use Notification\Domain\Model\WebhookChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Contract\Organization\GetTeamAccess;
use Shared\Application\Contract\Organization\GetVisibleProjects;
use Shared\Application\Contract\Organization\ListTeamEnvironments;
use Shared\Application\Contract\Organization\TeamAccess;
use Shared\Application\Contract\Organization\TeamEnvironment;
use Shared\Application\Contract\Organization\VisibleProjects;
use Shared\Application\Query\PageRequest;
use Shared\Domain\Authorization\Permission;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\GrantedPermissions;
use Tests\Identity\Support\FixedActor;
use Tests\Notification\Support\InMemoryRules;
use Tests\Notification\Support\InMemorySubscriptions;
use Tests\Notification\Support\InMemoryWebhooks;
use Tests\Organization\Support\FixedQueries;

/**
 * The rules of a scope checked against the team (their environment one of the project, their webhooks the team's),
 * one's own subscription, and what the pages read.
 */
final class RulesTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string OTHER_PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f12';
    private const string PROD = '019a6b2c-1f00-7a11-8b22-3c44d55e6f21';
    private const string STAGING = '019a6b2c-1f00-7a11-8b22-3c44d55e6f22';
    private const string ELSEWHERE = '019a6b2c-1f00-7a11-8b22-3c44d55e6f23';
    private const string USER = '019a6b2c-1f00-7a11-8b22-3c44d55e6f51';

    private InMemoryRules $rules;
    private InMemoryWebhooks $webhooks;
    private InMemorySubscriptions $subscriptions;
    private GrantedPermissions $permissions;
    private FixedQueries $queries;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->rules = new InMemoryRules();
        $this->webhooks = new InMemoryWebhooks();
        $this->subscriptions = new InMemorySubscriptions();
        $this->permissions = new GrantedPermissions();
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage, Permission::ProjectNotificationsSubscribe];
        $this->clock = new MockClock('2026-11-13 09:00:00');
        $this->queries = new FixedQueries([
            GetTeamAccess::class => static fn (object $q): TeamAccess => new TeamAccess(self::TEAM, 'Platform', 'team_member', false),
            GetVisibleProjects::class => static fn (object $q): VisibleProjects => new VisibleProjects(true),
            ListTeamEnvironments::class => static fn (object $q): array => [
                new TeamEnvironment(self::ELSEWHERE, 'production', self::OTHER_PROJECT, 'Blog'),
                new TeamEnvironment(self::PROD, 'production', self::PROJECT, 'Shop'),
                new TeamEnvironment(self::STAGING, 'staging', self::PROJECT, 'Shop'),
            ],
        ]);
    }

    #[Test]
    public function it_should_keep_a_rule_to_an_environment_of_its_project(): void
    {
        $this->define('r1', new RuleSettings('Prod', ['vulnerability_opened'], self::STAGING));
        self::assertSame(self::STAGING, $this->rules->rules[0]->environmentId()?->toRfc4122());

        foreach ([self::ELSEWHERE, Uuid::v7()->toRfc4122()] as $environment) {
            try {
                $this->define('r2', new RuleSettings('Elsewhere', ['vulnerability_opened'], $environment));
                self::fail($environment);
            } catch (ResourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->define('r3', new RuleSettings('Fleet', ['agent_pending'], self::PROD), null);
            self::fail('The fleet has no environment.');
        } catch (ResourceNotFound) {
            self::assertCount(1, $this->rules->rules);
        }
    }

    #[Test]
    public function it_should_call_the_webhooks_of_the_team_only_and_know_its_events(): void
    {
        $hook = $this->webhook(self::TEAM);
        $foreign = $this->webhook('019a6b2c-1f00-7a11-8b22-3c44d55e6f02');
        $this->define('r1', new RuleSettings('Hooks', ['outdated'], webhookIds: [$hook->id()->toRfc4122()]));
        self::assertSame([$hook->id()->toRfc4122()], $this->rules->rules[0]->webhookIds());

        foreach ([new RuleSettings('Foreign', ['outdated'], webhookIds: [$foreign->id()->toRfc4122()]), new RuleSettings('Unknown', ['license'])] as $settings) {
            try {
                $this->define('r2', $settings);
                self::fail($settings->name);
            } catch (InvalidNotificationSetting) {
                $this->addToAssertionCount(1);
            }
        }

        new RedefineRuleHandler(new RuleOfScope($this->rules, $this->access()), $this->input())(new RedefineRule($this->rules->rules[0]->id()->toRfc4122(), new RuleSettings('Prod only', ['outdated'], self::PROD, emailSubscribers: false, webhookIds: [$hook->id()->toRfc4122()])));
        self::assertSame(['Prod only', self::PROD, false], [$this->rules->rules[0]->name(), $this->rules->rules[0]->environmentId()?->toRfc4122(), $this->rules->rules[0]->emailsSubscribers()]);
    }

    #[Test]
    public function it_should_list_the_rules_with_their_names(): void
    {
        $hook = $this->webhook(self::TEAM);
        $this->define('r1', new RuleSettings('Prod', ['vulnerability_opened'], self::PROD, 'high', 7.0, true, 0.1, 24, [$hook->id()->toRfc4122()]));
        $this->define('r2', new RuleSettings('All', ['outdated']));

        $page = new ListRulesHandler($this->access(), $this->rules, $this->webhooks, $this->queries)(new ListRules(self::TEAM, self::PROJECT, new PageRequest()));
        self::assertSame(['Prod', 'All'], array_map(static fn ($row): string => $row->name, $page->items));
        $prod = $page->items[0];
        self::assertSame(['production', ['vulnerability_opened'], 'high', 7.0, true, 0.1, ['Hook'], true], [$prod->environment, $prod->events, $prod->minSeverity, $prod->minCvss, $prod->knownExploitedOnly, $prod->minEpss, $prod->webhooks, $prod->emailSubscribers]);
        self::assertNull($page->items[1]->environment);

        $this->queries->asked = [];
        $this->rules->rules = [$this->rules->rules[1]];
        new ListRulesHandler($this->access(), $this->rules, $this->webhooks, $this->queries)(new ListRules(self::TEAM, self::PROJECT, new PageRequest(search: 'all')));
        self::assertNotContains(ListTeamEnvironments::class, array_map(static fn (object $q): string => $q::class, $this->queries->asked), 'No environment to name: not asked.');
    }

    #[Test]
    public function it_should_offer_the_environments_of_the_project_only(): void
    {
        $this->webhook(self::TEAM);
        $this->subscriptions->add(Subscription::subscribe(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::fromString(self::USER), Frequency::Weekly, $this->clock->now()));

        $view = $this->notifications(self::PROJECT);
        self::assertEquals([new Option(self::PROD, 'production'), new Option(self::STAGING, 'staging')], $view->environments);
        self::assertSame('weekly', $view->myFrequency);
        self::assertTrue($view->manages);
        self::assertFalse($view->managesChannels);
        self::assertTrue($view->subscribes);
        self::assertSame(['Hook'], array_map(static fn (Option $o): string => $o->label, $view->webhooks));
        self::assertNotContains('agent_silent', $view->events);

        $fleet = $this->notifications(null);
        self::assertSame([], $fleet->environments);
        self::assertContains('agent_silent', $fleet->events);
        self::assertNull($fleet->myFrequency);
        self::assertFalse($fleet->manages);
        self::assertSame([], $fleet->webhooks, 'Offered to who manages only.');
    }

    #[Test]
    public function it_should_subscribe_change_and_turn_off(): void
    {
        $subscribe = fn (string $frequency, ?string $project = self::PROJECT) => new SubscribeHandler($this->access(), $this->subscriptions, FixedActor::user(self::USER), $this->clock)(new Subscribe(self::TEAM, $project, $frequency));

        $subscribe('off');
        self::assertSame([], $this->subscriptions->subscriptions, 'Nothing to turn off.');
        $subscribe('daily');
        $subscribe('weekly');
        self::assertCount(1, $this->subscriptions->subscriptions);
        self::assertSame(Frequency::Weekly, $this->subscriptions->subscriptions[0]->frequency());
        self::assertSame(self::USER, $this->subscriptions->subscriptions[0]->userId()->toRfc4122());
        $subscribe('off');
        self::assertSame(Frequency::Off, $this->subscriptions->subscriptions[0]->frequency(), 'Kept, off.');

        $subscribe('immediate', null);
        self::assertNull($this->subscriptions->subscriptions[1]->projectId());

        $this->expectException(InvalidNotificationSetting::class);
        $subscribe('hourly');
    }

    private function notifications(?string $projectId): NotificationsView
    {
        return new GetNotificationsHandler($this->access(), $this->subscriptions, $this->webhooks, $this->queries, FixedActor::user(self::USER))(new GetNotifications(self::TEAM, $projectId));
    }

    private function define(string $id, RuleSettings $settings, ?string $project = self::PROJECT): void
    {
        $this->permissions->team[self::TEAM][] = Permission::TeamChannelsManage;
        new DefineRuleHandler($this->access(), $this->input(), $this->rules, $this->clock)(new DefineRule(Uuid::v7()->toRfc4122(), self::TEAM, $project, $settings));
        $this->permissions->team[self::TEAM] = [Permission::ProjectNotificationsManage, Permission::ProjectNotificationsSubscribe];
    }

    private function webhook(string $team): WebhookChannel
    {
        $webhook = WebhookChannel::register(Uuid::v7(), Uuid::fromString($team), 'Hook', 'https://hooks.example.com', 's', $this->clock->now());
        $this->webhooks->add($webhook);

        return $webhook;
    }

    private function input(): RuleInput
    {
        return new RuleInput($this->queries, $this->webhooks);
    }

    private function access(): NotificationAccess
    {
        return new NotificationAccess($this->queries, $this->permissions);
    }
}
