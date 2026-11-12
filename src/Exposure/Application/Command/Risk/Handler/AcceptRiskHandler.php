<?php

declare(strict_types=1);

namespace Exposure\Application\Command\Risk\Handler;

use Exposure\Application\Command\Risk\AcceptRisk;
use Exposure\Domain\Model\InvalidRiskAcceptance;
use Exposure\Domain\Model\RiskAcceptance;
use Exposure\Domain\Repository\RiskAcceptanceRepository;
use Psr\Clock\ClockInterface;
use Shared\Application\Bus\CommandHandler;
use Shared\Application\Security\CurrentActor;
use Symfony\Component\Uid\Uuid;

final readonly class AcceptRiskHandler implements CommandHandler
{
    public function __construct(private RiskDecision $decision, private RiskAcceptanceRepository $acceptances, private CurrentActor $actor, private ClockInterface $clock)
    {
    }

    public function __invoke(AcceptRisk $command): void
    {
        $finding = $this->decision->finding($command->findingId);
        $expiresOn = \DateTimeImmutable::createFromFormat('!Y-m-d', $command->expiresOn);
        if (false === $expiresOn || $expiresOn->format('Y-m-d') !== $command->expiresOn) {
            throw new InvalidRiskAcceptance('An expiry date (2027-02-30 is no date).');
        }

        $now = $this->clock->now();
        $by = $this->actor->actor()->userId();
        foreach ($this->acceptances->ofFinding($finding->id()) as $running) {
            $running->revoke($by, $now);
        }

        $this->acceptances->add(RiskAcceptance::accept(Uuid::v7(), $finding, $command->justification, $expiresOn, $by, $now));
    }
}
