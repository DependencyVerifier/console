<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Application\Audit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\Actor;
use Shared\Application\Audit\ActorType;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Audit\AuditAction;
use Shared\Application\Audit\AuditContext;
use Shared\Application\Audit\AuditEntry;
use Shared\Application\Audit\Outcome;
use Shared\Application\Audit\OutcomeKind;
use Shared\Application\Audit\RequestContext;
use Shared\Application\Audit\Scope;
use Shared\Application\Audit\Target;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\String\u;

final class ValueObjectsTest extends TestCase
{
    #[Test]
    public function it_should_identify_a_user_actor(): void
    {
        $id = Uuid::v7();
        $actor = Actor::user($id);

        self::assertSame(ActorType::User, $actor->type);
        self::assertTrue($actor->isUser());
        self::assertSame($id, $actor->userId());
        self::assertSame($id->toRfc4122(), $actor->idForStorage());
    }

    /**
     * @return iterable<string, array{Actor, ActorType}>
     */
    public static function provideAnonymousActors(): iterable
    {
        yield 'cli' => [Actor::cli(), ActorType::Cli];
        yield 'system' => [Actor::system(), ActorType::System];
        yield 'anonymous' => [Actor::anonymous(), ActorType::Anonymous];
    }

    #[Test]
    #[DataProvider('provideAnonymousActors')]
    public function it_should_give_no_id_to_non_user_actors(Actor $actor, ActorType $type): void
    {
        self::assertSame($type, $actor->type);
        self::assertFalse($actor->isUser());
        self::assertNull($actor->idForStorage());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('A "%s" actor has no id.', $type->value));
        $actor->userId();
    }

    #[Test]
    public function it_should_describe_targets(): void
    {
        $target = Target::of('team', 'abc');
        self::assertSame('team', $target->type);
        self::assertSame('abc', $target->id());
        self::assertSame('abc', $target->idForStorage());
        self::assertFalse($target->isNone());
        self::assertSame('invitation_link', Target::of('invitation_link', 'x')->type);
        self::assertSame('a'.u('b')->repeat(63)->toString(), Target::of('a'.u('b')->repeat(63)->toString(), 'x')->type);

        $none = Target::none();
        self::assertSame('none', $none->type);
        self::assertTrue($none->isNone());
        self::assertNull($none->idForStorage());
        $this->expectException(\LogicException::class);
        $none->id();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidTargetTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'upper' => ['Team'];
        yield 'digit' => ['team2'];
        yield 'leading space' => [' team'];
        yield 'trailing space' => ['team '];
        yield 'too long' => ['a'.u('b')->repeat(64)->toString()];
    }

    #[Test]
    #[DataProvider('provideInvalidTargetTypes')]
    public function it_should_refuse_invalid_target_types(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Target::of($type, 'x');
    }

    #[Test]
    public function it_should_hold_scopes(): void
    {
        self::assertNull(Scope::none()->teamIdForStorage());
        self::assertNull(Scope::none()->projectIdForStorage());
        self::assertSame('t', Scope::team('t')->teamIdForStorage());
        self::assertNull(Scope::team('t')->projectIdForStorage());
        self::assertSame('t', Scope::project('t', 'p')->teamIdForStorage());
        self::assertSame('p', Scope::project('t', 'p')->projectIdForStorage());
    }

    #[Test]
    public function it_should_qualify_outcomes(): void
    {
        self::assertSame(OutcomeKind::Success, Outcome::success()->kind);
        self::assertSame('', Outcome::success()->reason);
        self::assertSame(OutcomeKind::Failure, Outcome::failure('technical')->kind);
        self::assertSame('technical', Outcome::failure('technical')->reason);
        self::assertSame(OutcomeKind::Denied, Outcome::denied('rule:last_team_admin_required')->kind);
        self::assertSame('rule:last_team_admin_required', Outcome::denied('rule:last_team_admin_required')->reason);
        self::assertSame('lock_contention2', Outcome::denied('lock_contention2')->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidReasons(): iterable
    {
        yield 'upper' => ['Quota'];
        yield 'two colons' => ['rule:a:b'];
        yield 'leading space' => [' quota'];
        yield 'trailing space' => ['quota '];
        yield 'empty suffix' => ['rule:'];
    }

    #[Test]
    #[DataProvider('provideInvalidReasons')]
    public function it_should_refuse_invalid_reasons(string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Outcome::denied($reason);
    }

    #[Test]
    public function it_should_bound_the_user_agent(): void
    {
        $context = RequestContext::of('192.0.2.1', u('é')->repeat(600)->toString(), 'req');
        self::assertSame('192.0.2.1', $context->ip);
        self::assertSame(u('é')->repeat(RequestContext::MAX_USER_AGENT)->toString(), $context->userAgent);
        self::assertSame('req', $context->requestId);
        self::assertSame('curl', RequestContext::of(null, 'curl', 'r')->userAgent);

        $cli = RequestContext::cli('r2');
        self::assertNull($cli->ip);
        self::assertSame('cli', $cli->userAgent);
        self::assertSame('r2', $cli->requestId);
    }

    #[Test]
    public function it_should_wrap_an_audited_cause(): void
    {
        $cause = new \DomainException('Quota reached.');
        $action = AuditAction::named('organization.create_project');
        $audited = new AlreadyAudited($cause, $action);

        self::assertSame($cause, $audited->cause());
        self::assertSame($cause, $audited->getPrevious());
        self::assertSame($action, $audited->action());
        self::assertSame('Quota reached.', $audited->getMessage());
    }

    #[Test]
    public function it_should_assemble_an_entry(): void
    {
        $at = new \DateTimeImmutable();
        $entry = new AuditEntry(Actor::cli(), AuditAction::named('a.b'), Target::none(), Scope::none(), Outcome::success(), RequestContext::cli('r'), AuditContext::empty(), $at);

        self::assertSame($at, $entry->occurredAt);
        self::assertSame('a.b', $entry->action->name);
    }
}
