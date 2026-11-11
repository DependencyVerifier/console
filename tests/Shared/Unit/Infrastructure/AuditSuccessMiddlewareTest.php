<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Infrastructure;

use Inventory\Application\Command\Inventory\ProcessInventory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Audit\AuditEntry;
use Shared\Application\Audit\AuditTrail;
use Shared\Infrastructure\Audit\AuditEntryFactory;
use Shared\Infrastructure\Messenger\AuditSuccessMiddleware;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Tests\Identity\Support\FixedActor;
use Tests\Identity\Support\FixedRequest;

/**
 * A command routed to a queue crosses the bus twice: sent (custom middlewares run before send_message), then
 * received and handled. Its success is written once, by the pass that handles it.
 */
final class RecordingTrail implements AuditTrail
{
    /** @var \ArrayObject<int, AuditEntry> */
    public \ArrayObject $entries;

    public function __construct()
    {
        $this->entries = new \ArrayObject();
    }

    public function record(AuditEntry $entry): void
    {
        $this->entries->append($entry);
    }
}

final class AuditSuccessMiddlewareTest extends TestCase
{
    #[Test]
    public function it_should_audit_a_routed_command_once_when_it_is_handled(): void
    {
        $trail = new RecordingTrail();
        $middleware = new AuditSuccessMiddleware($trail, new AuditEntryFactory(FixedActor::user(), new FixedRequest(), new MockClock(), []));
        $command = new Envelope(new ProcessInventory('019a6b31-5e00-7f12-a3b4-c5d6e7f80912'));

        $middleware->handle($command, $this->stack(new SentStamp('sender', 'ingest'), new HandledStamp('x', 'synchronous transport inside')));
        self::assertCount(0, $trail->entries, 'Only sent on this pass.');

        $middleware->handle($command->with(new ReceivedStamp('ingest')), $this->stack(new HandledStamp('x', 'handler')));
        self::assertCount(1, $trail->entries, 'Received and handled: one entry.');

        $middleware->handle($command, $this->stack(new HandledStamp('x', 'handler')));
        self::assertCount(2, $trail->entries, 'Not routed: handled on the spot.');
        self::assertSame('inventory.process', $trail->entries[1]?->action->name);
    }

    private function stack(object ...$stamps): StackInterface
    {
        /** @var list<StampInterface> $stamps */
        $next = new readonly class($stamps) implements MiddlewareInterface {
            /**
             * @param list<StampInterface> $stamps
             */
            public function __construct(private array $stamps)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope->with(...$this->stamps);
            }
        };

        return new StackMiddleware($next);
    }
}
