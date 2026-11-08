<?php

declare(strict_types=1);

namespace Shared\Application\Health\Query\Handler;

use Shared\Application\Bus\QueryHandler;
use Shared\Application\Health\HealthProbe;
use Shared\Application\Health\Query\GetHealthReport;
use Shared\Domain\Health\HealthReport;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class GetHealthReportHandler implements QueryHandler
{
    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(
        #[AutowireIterator(HealthProbe::class)]
        private iterable $probes,
    ) {
    }

    public function __invoke(GetHealthReport $query): HealthReport
    {
        $start = hrtime(true);

        $results = [];
        foreach ($this->probes as $probe) {
            $results[] = $probe->check();
        }

        return new HealthReport($results, (hrtime(true) - $start) / 1_000_000);
    }
}
