<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Domain\Health;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Domain\Health\HealthReport;
use Shared\Domain\Health\ProbeResult;

final class HealthReportTest extends TestCase
{
    #[Test]
    public function it_should_be_healthy_when_every_probe_is_up(): void
    {
        $report = new HealthReport([ProbeResult::up('database'), ProbeResult::up('queue')], 1.5);

        self::assertTrue($report->isHealthy());
        self::assertSame(1.5, $report->durationMs);
    }

    #[Test]
    public function it_should_be_healthy_without_any_probe(): void
    {
        self::assertTrue(new HealthReport([], 0.0)->isHealthy());
    }

    #[Test]
    public function it_should_be_unhealthy_when_one_probe_is_down(): void
    {
        $report = new HealthReport([ProbeResult::up('database'), ProbeResult::down('queue', 'timeout')], 1.0);

        self::assertFalse($report->isHealthy());
    }

    #[Test]
    public function it_should_be_unhealthy_when_the_first_probe_is_down(): void
    {
        $report = new HealthReport([ProbeResult::down('database', 'refused'), ProbeResult::up('queue')], 1.0);

        self::assertFalse($report->isHealthy());
    }

    #[Test]
    public function it_should_summarize_probes_without_failure_details(): void
    {
        $report = new HealthReport([ProbeResult::up('database'), ProbeResult::down('queue', 'secret detail')], 1.0);

        self::assertSame(['database' => 'up', 'queue' => 'down'], $report->summary());
    }
}
