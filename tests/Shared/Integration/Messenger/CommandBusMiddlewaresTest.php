<?php

declare(strict_types=1);

namespace Tests\Shared\Integration\Messenger;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Shared\Application\Audit\AlreadyAudited;
use Shared\Application\Authorization\PermissionDenied;
use Shared\Application\Authorization\ResourceNotFound;
use Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Fixtures\Doctrine\FixtureEvent;
use Tests\Fixtures\Messenger\FixtureCommand;
use Tests\Fixtures\Messenger\FixtureEventHandler;
use Tests\Fixtures\Messenger\FixtureRuleViolated;
use Tests\Support\AuditLog;
use Tests\Support\FixtureSchema;

/**
 * The command bus contract: one audit entry per command, failures audited after the rollback, refusals thrown as
 * AlreadyAudited, events published after the commit, a failing reaction never requalifies the command, and a
 * cancelled command leaves nothing behind in the EntityManager.
 */
final class CommandBusMiddlewaresTest extends KernelTestCase
{
    private CommandBus $bus;
    private Connection $connection;
    private string $id;

    protected function setUp(): void
    {
        FixtureSchema::ensure();
        self::bootKernel();
        $this->bus = self::getContainer()->get(CommandBus::class);
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        FixtureEventHandler::$received = [];
        $this->id = Uuid::v7()->toRfc4122();
        $this->bus->dispatch(new FixtureCommand('create', $this->id, 'initial'));
    }

    #[Test]
    public function it_should_audit_a_success_inside_the_transaction(): void
    {
        $entries = AuditLog::entries($this->connection, 'fixture.create');

        self::assertCount(1, $entries);
        self::assertSame('success', $entries[0]['outcome']);
        self::assertSame('fixture', $entries[0]['target_type']);
        self::assertSame($this->id, $entries[0]['target_id']);
        self::assertSame('{"behaviour": "create"}', $entries[0]['context']);
    }

    #[Test]
    public function it_should_audit_a_failure_despite_the_rollback(): void
    {
        $audited = $this->dispatchExpectingFailure(new FixtureCommand('technical', $this->id));

        self::assertInstanceOf(\RuntimeException::class, $audited->cause());
        self::assertSame('fixture.technical', $audited->action()->name);
        $entries = AuditLog::entries($this->connection, 'fixture.technical');
        self::assertCount(1, $entries);
        self::assertSame(['failure', 'technical'], [$entries[0]['outcome'], $entries[0]['reason']]);
    }

    #[Test]
    public function it_should_audit_each_refusal_exactly_once(): void
    {
        $cases = ['permission' => [PermissionDenied::class, 'permission'], 'not_found' => [ResourceNotFound::class, 'not_found'], 'rule' => [FixtureRuleViolated::class, 'rule:fixture_rule']];
        foreach ($cases as $behaviour => [$class, $reason]) {
            $audited = $this->dispatchExpectingFailure(new FixtureCommand($behaviour, $this->id));

            self::assertInstanceOf($class, $audited->cause());
            $entries = AuditLog::entries($this->connection, 'fixture.'.$behaviour);
            self::assertCount(1, $entries, $behaviour);
            self::assertSame(['denied', $reason], [$entries[0]['outcome'], $entries[0]['reason']]);
        }
    }

    #[Test]
    public function it_should_publish_events_after_the_commit_even_for_an_unchanged_root(): void
    {
        $this->bus->dispatch(new FixtureCommand('rename', $this->id, 'renamed'));

        self::assertEquals([new FixtureEvent($this->id, 'renamed')], FixtureEventHandler::$received);
    }

    #[Test]
    public function it_should_never_requalify_a_command_whose_reaction_fails(): void
    {
        // touch() records an event without changing the row; its reaction dispatches a failing nested command.
        $this->bus->dispatch(new FixtureCommand('touch', $this->id));

        self::assertEquals([new FixtureEvent($this->id, 'touched')], FixtureEventHandler::$received);
        self::assertSame(['success'], array_column(AuditLog::entries($this->connection, 'fixture.touch'), 'outcome'));
        self::assertSame(['denied'], array_column(AuditLog::entries($this->connection, 'fixture.rule'), 'outcome'));
        $failed = AuditLog::entries($this->connection, 'event.handler_failed');
        self::assertCount(1, $failed);
        $context = json_decode($failed[0]['context'], true);
        self::assertIsArray($context);
        self::assertIsString($context['handler']);
        self::assertSame(FixtureEvent::class, $context['event']);
        self::assertSame('fixture.rule', $context['failed_action']);
        self::assertSame(FixtureRuleViolated::class, $context['exception']);
        self::assertStringContainsString('FixtureEventHandler', $context['handler']);
    }

    #[Test]
    public function it_should_leave_nothing_of_a_cancelled_command_to_the_next_one(): void
    {
        $this->dispatchExpectingFailure(new FixtureCommand('rename_then_fail', $this->id, 'cancelled'));

        $other = Uuid::v7()->toRfc4122();
        $this->bus->dispatch(new FixtureCommand('create', $other, 'other'));

        self::assertSame('initial', $this->connection->fetchOne('SELECT name FROM fixture_aggregate WHERE id = :id', ['id' => $this->id]));
        self::assertSame([], FixtureEventHandler::$received, 'The event of the cancelled command must not be published.');
        $entries = AuditLog::entries($this->connection, 'fixture.rename_then_fail');
        self::assertSame('fixture', $entries[0]['target_type']);
    }

    private function dispatchExpectingFailure(FixtureCommand $command): AlreadyAudited
    {
        try {
            $this->bus->dispatch($command);
        } catch (AlreadyAudited $audited) {
            return $audited;
        }

        self::fail('The command should have failed.');
    }
}
