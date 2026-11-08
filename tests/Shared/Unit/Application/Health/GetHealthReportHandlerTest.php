<?php

declare(strict_types=1);

namespace Tests\Shared\Unit\Application\Health;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Application\Health\HealthProbe;
use Shared\Application\Health\Query\GetHealthReport;
use Shared\Application\Health\Query\Handler\GetHealthReportHandler;
use Shared\Domain\Health\ProbeResult;

final class GetHealthReportHandlerTest extends TestCase
{
    #[Test]
    public function it_should_run_every_probe_in_order(): void
    {
        $handler = new GetHealthReportHandler([
            $this->probe(ProbeResult::up('database')),
            $this->probe(ProbeResult::down('queue', 'timeout')),
        ]);

        $report = $handler(new GetHealthReport());

        self::assertSame(['database' => 'up', 'queue' => 'down'], $report->summary());
        self::assertFalse($report->isHealthy());
    }

    #[Test]
    public function it_should_measure_the_time_spent_in_probes_in_milliseconds(): void
    {
        $slow = new class implements HealthProbe {
            public function check(): ProbeResult
            {
                usleep(5_000);

                return ProbeResult::up('slow');
            }
        };

        $report = new GetHealthReportHandler([$slow])(new GetHealthReport());

        self::assertGreaterThanOrEqual(5.0, $report->durationMs);
        self::assertLessThan(1_000.0, $report->durationMs);
    }

    private function probe(ProbeResult $result): HealthProbe
    {
        return new readonly class($result) implements HealthProbe {
            public function __construct(private ProbeResult $result)
            {
            }

            public function check(): ProbeResult
            {
                return $this->result;
            }
        };
    }
}
