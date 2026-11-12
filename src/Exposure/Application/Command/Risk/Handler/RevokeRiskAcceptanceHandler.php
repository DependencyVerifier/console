<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Risk\Handler;

use Exposure\Application\Command\Risk\RevokeRiskAcceptance;
use Exposure\Domain\Repository\RiskAcceptanceRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;

final readonly class RevokeRiskAcceptanceHandler implements CommandHandler
{
    public function __construct(private RiskDecision $decision, private RiskAcceptanceRepository $acceptances, private CurrentActor $actor, private ClockInterface $clock)
    {
    }

    public function __invoke(RevokeRiskAcceptance $command): void
    {
        $finding = $this->decision->finding($command->findingId);
        foreach ($this->acceptances->ofFinding($finding->id()) as $acceptance) {
            $acceptance->revoke($this->actor->actor()->userId(), $this->clock->now());
        }
    }
}
