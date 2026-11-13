<?php

declare(strict_types=1);

namespace Tests\Notification\Unit;

use Notification\Domain\Model\InvalidNotificationSetting;
use Notification\Domain\Model\NotificationEvent;
use Notification\Domain\Model\NotificationRule;
use Notification\Domain\Model\NotificationSubject;
use Notification\Domain\Model\RuleThresholds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

/**
 * Who hears of what: the scope of a rule, its events, the thresholds of the vulnerabilities, the silence of an agent.
 */
final class NotificationRuleTest extends TestCase
{
    private const string TEAM = '019a6b2c-1f00-7a11-8b22-3c44d55e6f01';
    private const string PROJECT = '019a6b2c-1f00-7a11-8b22-3c44d55e6f11';
    private const string ENV = '019a6b2c-1f00-7a11-8b22-3c44d55e6f21';
    private const string WEBHOOK = '019a6b2c-1f00-7a11-8b22-3c44d55e6f31';

    #[Test]
    public function it_should_define_a_rule(): void
    {
        $now = new \DateTimeImmutable('2026-11-12 10:00');
        $hook = Uuid::fromString(self::WEBHOOK);
        $rule = NotificationRule::define(Uuid::v7(), Uuid::fromString(self::TEAM), Uuid::fromString(self::PROJECT), Uuid::fromString(self::ENV), '  Prod  ', [NotificationEvent::VulnerabilityOpened, NotificationEvent::VulnerabilityOpened, NotificationEvent::EndOfLife], new RuleThresholds('high', 7.5, true, 0.2, 48), [$hook, $hook], false, $now);

        self::assertSame(self::TEAM, $rule->teamId()->toRfc4122());
        self::assertSame(self::PROJECT, $rule->projectId()?->toRfc4122());
        self::assertSame(self::ENV, $rule->environmentId()?->toRfc4122());
        self::assertSame('Prod', $rule->name());
        self::assertSame([NotificationEvent::VulnerabilityOpened, NotificationEvent::EndOfLife], $rule->events());
        self::assertEquals(new RuleThresholds('high', 7.5, true, 0.2, 48), $rule->thresholds());
        self::assertSame([self::WEBHOOK], $rule->webhookIds());
        self::assertFalse($rule->emailsSubscribers());
        self::assertSame($now, $rule->createdAt());
    }

    #[Test]
    public function it_should_keep_the_agents_to_the_fleet(): void
    {
        $project = $this->rule([NotificationEvent::AgentPending, NotificationEvent::Outdated, NotificationEvent::AgentSilent]);
        self::assertSame([NotificationEvent::Outdated], $project->events());

        $fleet = $this->rule([NotificationEvent::AgentPending, NotificationEvent::AgentSilent], project: null);
        self::assertSame([NotificationEvent::AgentPending, NotificationEvent::AgentSilent], $fleet->events());

        $this->expectExceptionObject(new InvalidNotificationSetting('At least one event of this scope.'));
        $this->rule([NotificationEvent::AgentSilent]);
    }

    /**
     * @return iterable<string, array{\Closure(self): mixed, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'no name' => [static fn (self $t): mixed => $t->rule(name: '   '), 'A name of 1 to 100 characters.'];
        yield 'long name' => [static fn (self $t): mixed => $t->rule(name: u('é')->repeat(101)->toString()), 'A name of 1 to 100 characters.'];
        yield 'no event' => [static fn (self $t): mixed => $t->rule([]), 'At least one event of this scope.'];
        yield 'environment of no project' => [static fn (self $t): mixed => $t->rule(project: null, environment: self::ENV), 'An environment belongs to a project.'];
        yield 'nobody' => [static fn (self $t): mixed => $t->rule(webhooks: [], email: false), 'Somebody to tell: a webhook or the subscribers.'];
    }

    /**
     * @param \Closure(self): mixed $define
     */
    #[Test]
    #[DataProvider('invalidRules')]
    public function it_should_refuse_an_invalid_rule(\Closure $define, string $message): void
    {
        $this->expectExceptionObject(new InvalidNotificationSetting($message));
        $define($this);
    }

    #[Test]
    public function it_should_accept_the_limits(): void
    {
        self::assertSame(u('é')->repeat(100)->toString(), $this->rule(name: u('é')->repeat(100)->toString())->name());
        self::assertSame([], $this->rule(webhooks: [], email: true)->webhookIds());
        self::assertTrue($this->rule(webhooks: [], email: true)->emailsSubscribers());
    }

    #[Test]
    public function it_should_redefine_a_rule_in_its_scope(): void
    {
        $rule = $this->rule(environment: self::ENV);
        $rule->redefine(null, 'Toutes', [NotificationEvent::Outdated], new RuleThresholds(minCvss: 9.0), [], true);

        self::assertNull($rule->environmentId());
        self::assertSame('Toutes', $rule->name());
        self::assertSame([NotificationEvent::Outdated], $rule->events());
        self::assertEquals(new RuleThresholds(minCvss: 9.0), $rule->thresholds());
        self::assertSame([], $rule->webhookIds());
        self::assertTrue($rule->emailsSubscribers());

        $fleet = $this->rule(project: null);
        $this->expectExceptionObject(new InvalidNotificationSetting('An environment belongs to a project.'));
        $fleet->redefine(Uuid::fromString(self::ENV), 'Parc', [NotificationEvent::Outdated], new RuleThresholds(), [], true);
    }

    #[Test]
    public function it_should_forget_a_deleted_webhook(): void
    {
        $other = Uuid::v7();
        $rule = $this->rule(webhooks: [Uuid::fromString(self::WEBHOOK), $other]);
        $rule->forgetWebhook(Uuid::fromString(self::WEBHOOK));

        self::assertSame([$other->toRfc4122()], $rule->webhookIds());
    }

    #[Test]
    public function it_should_retain_the_events_of_its_scope(): void
    {
        $rule = $this->rule([NotificationEvent::Outdated, NotificationEvent::EndOfLife]);
        self::assertTrue($rule->retains(new NotificationSubject(NotificationEvent::Outdated, self::PROJECT, self::ENV)));
        self::assertTrue($rule->retains(new NotificationSubject(NotificationEvent::EndOfLife, self::PROJECT)));
        self::assertFalse($rule->retains(new NotificationSubject(NotificationEvent::VulnerabilityOpened, self::PROJECT)), 'Not its event.');
        self::assertFalse($rule->retains(new NotificationSubject(NotificationEvent::Outdated)), 'The fleet.');
        self::assertFalse($rule->retains(new NotificationSubject(NotificationEvent::Outdated, Uuid::v7()->toRfc4122())), 'Another project.');

        $environment = $this->rule([NotificationEvent::Outdated], environment: self::ENV);
        self::assertTrue($environment->retains(new NotificationSubject(NotificationEvent::Outdated, self::PROJECT, self::ENV)));
        self::assertFalse($environment->retains(new NotificationSubject(NotificationEvent::Outdated, self::PROJECT)), 'No environment.');
        self::assertFalse($environment->retains(new NotificationSubject(NotificationEvent::Outdated, self::PROJECT, Uuid::v7()->toRfc4122())));

        $fleet = $this->rule([NotificationEvent::AgentPending, NotificationEvent::Outdated], project: null);
        self::assertTrue($fleet->retains(new NotificationSubject(NotificationEvent::AgentPending)));
        self::assertTrue($fleet->retains(new NotificationSubject(NotificationEvent::Outdated)));
        self::assertFalse($fleet->retains(new NotificationSubject(NotificationEvent::Outdated, self::PROJECT)), 'A project is not the fleet.');
    }

    #[Test]
    public function it_should_hold_every_threshold_of_a_vulnerability(): void
    {
        $vulnerability = static fn (?string $severity = 'high', ?float $cvss = 7.5, bool $kev = true, ?float $epss = 0.2, NotificationEvent $event = NotificationEvent::VulnerabilityOpened): NotificationSubject => new NotificationSubject($event, self::PROJECT, null, $severity, $cvss, $kev, $epss);
        $rule = $this->rule([NotificationEvent::VulnerabilityOpened, NotificationEvent::VulnerabilityFixed, NotificationEvent::Outdated], thresholds: new RuleThresholds('high', 7.5, true, 0.2));

        self::assertTrue($rule->retains($vulnerability()), 'Every threshold met, exactly.');
        self::assertTrue($rule->retains($vulnerability('critical', 9.8, true, 0.9)));
        self::assertTrue($rule->retains($vulnerability(event: NotificationEvent::VulnerabilityFixed)));
        self::assertFalse($rule->retains($vulnerability(severity: 'medium')));
        self::assertFalse($rule->retains($vulnerability(severity: null)));
        self::assertFalse($rule->retains($vulnerability(severity: 'unknown')));
        self::assertFalse($rule->retains($vulnerability(cvss: 7.4)));
        self::assertFalse($rule->retains($vulnerability(cvss: null)));
        self::assertFalse($rule->retains($vulnerability(kev: false)));
        self::assertFalse($rule->retains($vulnerability(epss: 0.19)));
        self::assertFalse($rule->retains($vulnerability(epss: null)));
        self::assertFalse($rule->retains($vulnerability(event: NotificationEvent::VulnerabilityFixed, cvss: 1.0)));
        self::assertTrue($rule->retains(new NotificationSubject(NotificationEvent::Outdated, self::PROJECT)), 'Thresholds are for the vulnerabilities.');

        $open = $this->rule([NotificationEvent::VulnerabilityOpened]);
        self::assertTrue($open->retains($vulnerability(null, null, false, null)), 'No threshold: everything.');
        self::assertTrue($this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds('low'))->retains($vulnerability('low')));
        self::assertFalse($this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds('low'))->retains($vulnerability(null)), 'No severity is below any.');
        self::assertFalse($this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds(minCvss: 0.5))->retains($vulnerability(cvss: null)), 'No score is 0.');
        self::assertTrue($this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds(minCvss: 0.0))->retains($vulnerability(cvss: null)));
        self::assertTrue($this->rule([NotificationEvent::VulnerabilityOpened], thresholds: new RuleThresholds(minEpss: 0.0))->retains($vulnerability(epss: null)));
    }

    #[Test]
    public function it_should_tell_of_an_agent_silent_long_enough(): void
    {
        $rule = $this->rule([NotificationEvent::AgentSilent, NotificationEvent::AgentPending], project: null, thresholds: new RuleThresholds(silentHours: 48));

        self::assertTrue($rule->retains(new NotificationSubject(NotificationEvent::AgentSilent, silentHours: 48)));
        self::assertTrue($rule->retains(new NotificationSubject(NotificationEvent::AgentSilent, silentHours: 100)));
        self::assertFalse($rule->retains(new NotificationSubject(NotificationEvent::AgentSilent, silentHours: 47)));
        self::assertTrue($rule->retains(new NotificationSubject(NotificationEvent::AgentPending)), 'Pending: no silence.');
    }

    /**
     * @return iterable<string, array{\Closure(): RuleThresholds, string}>
     */
    public static function invalidThresholds(): iterable
    {
        yield 'severity' => [static fn (): RuleThresholds => new RuleThresholds('urgent'), 'A severity: low, medium, high or critical.'];
        yield 'cvss below' => [static fn (): RuleThresholds => new RuleThresholds(minCvss: -0.1), 'A CVSS score between 0 and 10.'];
        yield 'cvss above' => [static fn (): RuleThresholds => new RuleThresholds(minCvss: 10.1), 'A CVSS score between 0 and 10.'];
        yield 'epss below' => [static fn (): RuleThresholds => new RuleThresholds(minEpss: -0.01), 'An EPSS probability between 0 and 1.'];
        yield 'epss above' => [static fn (): RuleThresholds => new RuleThresholds(minEpss: 1.01), 'An EPSS probability between 0 and 1.'];
        yield 'silence below' => [static fn (): RuleThresholds => new RuleThresholds(silentHours: 0), 'A silence of 1 hour to 30 days.'];
        yield 'silence above' => [static fn (): RuleThresholds => new RuleThresholds(silentHours: 721), 'A silence of 1 hour to 30 days.'];
    }

    /**
     * @param \Closure(): RuleThresholds $thresholds
     */
    #[Test]
    #[DataProvider('invalidThresholds')]
    public function it_should_refuse_a_threshold_out_of_range(\Closure $thresholds, string $message): void
    {
        $this->expectExceptionObject(new InvalidNotificationSetting($message));
        $thresholds();
    }

    #[Test]
    public function it_should_accept_the_bounds_of_the_thresholds(): void
    {
        self::assertSame(0.0, new RuleThresholds(minCvss: 0.0)->minCvss);
        self::assertSame(10.0, new RuleThresholds(minCvss: 10.0)->minCvss);
        self::assertSame(0.0, new RuleThresholds(minEpss: 0.0)->minEpss);
        self::assertSame(1.0, new RuleThresholds(minEpss: 1.0)->minEpss);
        self::assertSame(1, new RuleThresholds(silentHours: 1)->silentHours);
        self::assertSame(720, new RuleThresholds(silentHours: 720)->silentHours);
        self::assertSame('critical', new RuleThresholds('critical')->minSeverity);
        self::assertSame(24, new RuleThresholds()->silentHours);
    }

    #[Test]
    public function it_should_judge_a_subject_with_nothing_more_than_its_event_and_scope(): void
    {
        $subject = new NotificationSubject(NotificationEvent::Outdated);

        self::assertNull($subject->projectId);
        self::assertNull($subject->environmentId);
        self::assertNull($subject->severity);
        self::assertNull($subject->cvss);
        self::assertFalse($subject->knownExploited);
        self::assertNull($subject->epss);
        self::assertSame(0, $subject->silentHours);
    }

    #[Test]
    public function it_should_know_the_events_of_the_fleet(): void
    {
        self::assertSame(
            [NotificationEvent::AgentSilent, NotificationEvent::AgentPending],
            array_values(array_filter(NotificationEvent::cases(), static fn (NotificationEvent $e): bool => $e->isFleetOnly())),
        );
    }

    /**
     * @param list<NotificationEvent> $events
     * @param list<Uuid>|null         $webhooks
     */
    public function rule(array $events = [NotificationEvent::Outdated], ?string $project = self::PROJECT, ?string $environment = null, string $name = 'Rule', ?RuleThresholds $thresholds = null, ?array $webhooks = null, bool $email = true): NotificationRule
    {
        return NotificationRule::define(
            Uuid::v7(),
            Uuid::fromString(self::TEAM),
            null === $project ? null : Uuid::fromString($project),
            null === $environment ? null : Uuid::fromString($environment),
            $name,
            $events,
            $thresholds ?? new RuleThresholds(),
            $webhooks ?? [Uuid::fromString(self::WEBHOOK)],
            $email,
            new \DateTimeImmutable('2026-11-12'),
        );
    }
}
