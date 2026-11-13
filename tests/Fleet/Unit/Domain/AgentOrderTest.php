<?php

declare(strict_types=1);

namespace Tests\Fleet\Unit\Domain;

use Fleet\Domain\Model\AgentConfig;
use Fleet\Domain\Model\AgentNotApproved;
use Fleet\Domain\Model\AgentOrder;
use Fleet\Domain\Model\AgentOrderId;
use Fleet\Domain\Model\InvalidOrderPayload;
use Fleet\Domain\Model\OrderStatus;
use Fleet\Domain\Model\OrderType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Tests\Fleet\Support\AssertsRefusals;
use Tests\Fleet\Support\Fleet;

use function Symfony\Component\String\u;

final class AgentOrderTest extends TestCase
{
    use AssertsRefusals;

    #[Test]
    public function it_should_queue_then_sign_at_the_first_delivery_for_15_minutes(): void
    {
        $order = $this->scanNow();
        self::assertSame([Fleet::ORDER, Fleet::AGENT, Fleet::TEAM, OrderType::ScanNow, [], OrderStatus::Queued], [
            $order->id()->toString(), $order->agentId()->toString(), $order->teamId()->toRfc4122(), $order->type(), $order->payload(), $order->status(),
        ]);
        self::assertSame(Fleet::ADMIN, $order->requestedBy()?->toRfc4122());
        self::assertEquals(Fleet::now(), $order->createdAt());
        self::assertNull($order->issuedAt());
        self::assertNull($order->expiresAt());
        self::assertFalse($order->isSigned());
        self::assertFalse($order->isDeliverableAt(Fleet::now()), 'Queued: signed first.');

        $issued = Fleet::now()->modify('+1 minute');
        $order->issue($issued);
        self::assertEquals($issued, $order->issuedAt());
        self::assertEquals($issued->modify('+15 minutes'), $order->expiresAt());
        $order->deliver('signature', 'SHA256:console');
        self::assertSame([OrderStatus::Delivered, 'signature', true], [$order->status(), $order->signature(), $order->isSigned()]);
        self::assertTrue($order->isDeliverableAt($issued->modify('+14 minutes 59 seconds')), 'Sent again until a result.');
        self::assertFalse($order->isDeliverableAt($issued->modify('+15 minutes')));
        self::assertNull($order->finishedAt());
        self::assertNull($order->reason());
    }

    #[Test]
    public function it_should_issue_and_deliver_once(): void
    {
        $order = $this->scanNow();
        try {
            $order->deliver('unsigned', 'SHA256:console');
            self::fail('Delivered before being issued.');
        } catch (\LogicException) {
            self::assertSame(OrderStatus::Queued, $order->status());
        }

        $order->issue(Fleet::now());
        try {
            $order->issue(Fleet::now()->modify('+1 minute'));
            self::fail('Issued twice.');
        } catch (\LogicException) {
            self::assertEquals(Fleet::now(), $order->issuedAt());
        }

        $order->deliver('s1', 'SHA256:console');
        $this->expectException(\LogicException::class);
        $order->deliver('s2', 'SHA256:console');
    }

    #[Test]
    public function it_should_not_issue_a_delivered_order_again(): void
    {
        $order = $this->delivered();
        $this->expectException(\LogicException::class);
        $order->issue(Fleet::now());
    }

    #[Test]
    public function it_should_keep_the_first_result_only(): void
    {
        $order = $this->delivered();
        $finished = Fleet::now()->modify('+2 minutes');
        self::assertTrue($order->finish(OrderStatus::Executed, null, $finished, $finished->modify('+1 minute')));
        self::assertSame([OrderStatus::Executed, null], [$order->status(), $order->reason()]);
        self::assertEquals($finished, $order->finishedAt());
        self::assertFalse($order->finish(OrderStatus::Failed, 'scan_error', $finished, $finished), 'Results are sent again until a poll succeeds.');
        self::assertSame(OrderStatus::Executed, $order->status());
        self::assertFalse($this->scanNow()->finish(OrderStatus::Executed, null, $finished, $finished), 'Never delivered.');
    }

    #[Test]
    public function it_should_keep_machine_codes_only_and_never_a_future_time(): void
    {
        $now = Fleet::now()->modify('+2 minutes');
        $failed = $this->delivered();
        $failed->finish(OrderStatus::Failed, null, $now->modify('+1 hour'), $now);
        self::assertSame([OrderStatus::Failed, 'unspecified'], [$failed->status(), $failed->reason()]);
        self::assertEquals($now, $failed->finishedAt(), 'A result from the future is dated now.');

        $rejected = $this->delivered();
        $rejected->finish(OrderStatus::Rejected, 'bad_signature', $now, $now);
        self::assertSame('bad_signature', $rejected->reason());

        $executed = $this->delivered();
        $executed->finish(OrderStatus::Executed, 'already_running', $now, $now);
        self::assertSame('already_running', $executed->reason());

        foreach (['Free text', 'x'.u('a')->repeat(64)->toString(), '_x', '9x', "a\nb"] as $reason) {
            $order = $this->delivered();
            $order->finish(OrderStatus::Rejected, $reason, $now, $now);
            self::assertSame('unspecified', $order->reason(), $reason);
        }

        self::assertSame('a'.u('b')->repeat(63)->toString(), $this->finishedWith('a'.u('b')->repeat(63)->toString())->reason());
        self::assertSame('a', $this->finishedWith('a')->reason());
    }

    #[Test]
    public function it_should_refuse_a_result_that_is_not_one(): void
    {
        foreach ([OrderStatus::Queued, OrderStatus::Delivered, OrderStatus::Expired, OrderStatus::Cancelled] as $status) {
            $order = $this->delivered();
            self::assertFalse($order->finish($status, null, Fleet::now(), Fleet::now()), $status->value);
            self::assertSame(OrderStatus::Delivered, $order->status());
        }
    }

    #[Test]
    public function it_should_expire_60_seconds_after_its_validity_without_result(): void
    {
        $order = $this->delivered();
        self::assertFalse($order->expireIfLate(Fleet::now()->modify('+16 minutes -1 second')));
        self::assertSame(OrderStatus::Delivered, $order->status());
        self::assertTrue($order->expireIfLate(Fleet::now()->modify('+16 minutes')));
        self::assertSame(OrderStatus::Expired, $order->status());
        self::assertFalse($order->expireIfLate(Fleet::now()->modify('+1 day')));
        self::assertFalse($this->scanNow()->expireIfLate(Fleet::now()->modify('+1 day')), 'A queued order never expires.');
        self::assertFalse($order->finish(OrderStatus::Executed, null, Fleet::now(), Fleet::now()), 'Too late.');
    }

    #[Test]
    public function it_should_cancel_open_orders_only(): void
    {
        $queued = $this->scanNow();
        $queued->cancel();
        self::assertSame(OrderStatus::Cancelled, $queued->status());

        $delivered = $this->delivered();
        $delivered->cancel();
        self::assertSame(OrderStatus::Cancelled, $delivered->status());
        self::assertFalse($delivered->isDeliverableAt(Fleet::now()), 'A cancelled order is never sent again.');

        $done = $this->finishedWith('a');
        $done->cancel();
        self::assertSame(OrderStatus::Rejected, $done->status());
        self::assertSame([true, true, false, false, false, false, false], array_map(static fn (OrderStatus $status): bool => $status->isOpen(), OrderStatus::cases()));
    }

    #[Test]
    public function it_should_order_approved_agents_only(): void
    {
        $this->assertRefused(AgentNotApproved::class, 'agent_not_approved', static fn () => AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), Fleet::agent(), null, Fleet::now()));

        $config = new AgentConfig(360, ['/var/www', '/srv'], ['/opt/app', '/opt/legacy'], ['/var/www/**/cache', '/srv/tmp'], 3);
        $order = AgentOrder::setConfig(AgentOrderId::fromString(Fleet::ORDER), Fleet::approved(), $config, null, Fleet::now());
        self::assertSame(OrderType::SetConfig, $order->type());
        self::assertNull($order->requestedBy());
        self::assertSame(['config_version' => 3, 'scan_interval_minutes' => 360, 'discovery_roots' => ['/var/www', '/srv'], 'declared_paths' => ['/opt/app', '/opt/legacy'], 'exclusions' => ['/var/www/**/cache', '/srv/tmp']], $order->payload());
        self::assertEquals($config, AgentConfig::fromPayload($order->payload()));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'interval too short' => [static fn () => new AgentConfig(4)];
        yield 'interval too long' => [static fn () => new AgentConfig(10081)];
        yield 'negative version' => [static fn () => new AgentConfig(60, version: -1)];
        yield 'relative root' => [static fn () => new AgentConfig(60, ['srv'])];
        yield 'relative declared path' => [static fn () => new AgentConfig(60, declaredPaths: ['opt'])];
        yield 'relative exclusion' => [static fn () => new AgentConfig(60, exclusions: ['*.log'])];
        yield 'long path' => [static fn () => new AgentConfig(60, ['/'.u('a')->repeat(1024)->toString()])];
        yield 'nul byte' => [static fn () => new AgentConfig(60, ["/srv\0"])];
        yield 'too many paths' => [static fn () => new AgentConfig(60, array_fill(0, 51, '/srv'))];
        yield 'too many exclusions' => [static fn () => new AgentConfig(60, exclusions: array_fill(0, 51, '/srv'))];
        yield 'payload without interval' => [static fn () => AgentConfig::fromPayload(['config_version' => 1])];
        yield 'payload without version' => [static fn () => AgentConfig::fromPayload(['scan_interval_minutes' => 60])];
        yield 'payload with a map of paths' => [static fn () => AgentConfig::fromPayload(['config_version' => 1, 'scan_interval_minutes' => 60, 'discovery_roots' => ['a' => '/srv']])];
        yield 'payload with a string of paths' => [static fn () => AgentConfig::fromPayload(['config_version' => 1, 'scan_interval_minutes' => 60, 'declared_paths' => '/srv'])];
        yield 'payload with a number path' => [static fn () => AgentConfig::fromPayload(['config_version' => 1, 'scan_interval_minutes' => 60, 'exclusions' => [1]])];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_should_bound_a_configuration(\Closure $build): void
    {
        $this->assertRefused(InvalidOrderPayload::class, 'invalid_order_payload', $build);
    }

    #[Test]
    public function it_should_accept_the_bounds_of_a_configuration(): void
    {
        $paths = array_fill(0, 50, '/'.u('a')->repeat(1023)->toString());
        $config = new AgentConfig(5, $paths, $paths, $paths);
        self::assertSame([5, 0], [$config->scanIntervalMinutes, $config->version]);
        self::assertSame(10080, new AgentConfig(10080)->scanIntervalMinutes);
        self::assertEquals(new AgentConfig(60, version: 2), AgentConfig::fromPayload(['config_version' => 2, 'scan_interval_minutes' => 60]));
        self::assertEquals(new AgentConfig(60, ['/a'], version: 4), new AgentConfig(60, ['/a'])->withVersion(4));
    }

    private function scanNow(): AgentOrder
    {
        return AgentOrder::scanNow(AgentOrderId::fromString(Fleet::ORDER), Fleet::approved(), Uuid::fromString(Fleet::ADMIN), Fleet::now());
    }

    private function delivered(): AgentOrder
    {
        $order = $this->scanNow();
        $order->issue(Fleet::now());
        $order->deliver('signature', 'SHA256:console');

        return $order;
    }

    private function finishedWith(string $reason): AgentOrder
    {
        $order = $this->delivered();
        $order->finish(OrderStatus::Rejected, $reason, Fleet::now(), Fleet::now());

        return $order;
    }
}
