<?php

declare(strict_types=1);

namespace Exposure\Application\Query\Contract\Handler;

use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Repository\RiskAcceptanceRepository;
use Shared\Application\Bus\QueryHandler;
use Shared\Application\Contract\Exposure\ExpiringAcceptance;
use Shared\Application\Contract\Exposure\ListExpiringAcceptances;

final readonly class ListExpiringAcceptancesHandler implements QueryHandler
{
    public function __construct(private RiskAcceptanceRepository $acceptances)
    {
    }

    /**
     * @return list<ExpiringAcceptance>
     */
    public function __invoke(ListExpiringAcceptances $query): array
    {
        return array_map(static fn (RiskAcceptance $a): ExpiringAcceptance => new ExpiringAcceptance($a->id()->toRfc4122(), $a->findingId()->toRfc4122(), $a->teamId()->toRfc4122(), $a->expiresOn()), $this->acceptances->expiringBetween($query->from, $query->to));
    }
}
