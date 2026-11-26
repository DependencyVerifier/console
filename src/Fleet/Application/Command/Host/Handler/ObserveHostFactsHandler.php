<?php

declare(strict_types=1);

namespace Fleet\Application\Command\Host\Handler;

use Fleet\Application\Command\Host\ObserveHostFacts;
use Fleet\Application\Quota\FleetQuotas;
use Fleet\Domain\Model\AgentId;
use Fleet\Domain\Model\HostId;
use Fleet\Domain\Model\InvalidHostFacts;
use Fleet\Domain\Repository\AgentRepository;
use Fleet\Domain\Repository\HostRepository;
use Psr\Log\LoggerInterface;
use Shared\Application\Audit\CommandAudit;
use Shared\Application\Audit\Outcome;
use Shared\Application\Bus\CommandHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Under the team's fleet lock (the order of the enrollment, which also finds hosts by identity): the host must still
 * be of the team and its agent still report for it (an agent revoked while its inventory waited is ignored). The host
 * merges the facts and judges its promotion; this handler only checks that no other host of the team holds the
 * Docker identity. Facts out of bounds are dropped with a warning naming the field, never its value.
 */
final readonly class ObserveHostFactsHandler implements CommandHandler
{
    public function __construct(
        private HostRepository $hosts,
        private AgentRepository $agents,
        private FleetQuotas $quotas,
        private CommandAudit $audit,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ObserveHostFacts $command): void
    {
        $teamId = Uuid::fromString($command->teamId);
        $this->quotas->lock($teamId);
        $host = $this->hosts->find(HostId::fromString($command->hostId));
        $agent = $this->agents->find(AgentId::fromString($command->agentId));
        if (null === $host || !$host->teamId()->equals($teamId) || null === $agent || !$agent->reportsFor($host->id())) {
            return;
        }

        try {
            $host->observe(
                hostname: $command->hostname,
                osId: $command->osId,
                osVersionId: $command->osVersionId,
                osPrettyName: $command->osPrettyName,
                kernel: $command->kernel,
                arch: $command->arch,
                dockerEngineId: $command->dockerEngineId,
            );
        } catch (InvalidHostFacts $invalid) {
            $this->logger->warning('Host facts observed by an inventory dropped: {field} is out of bounds.', ['host_id' => $command->hostId, 'field' => $invalid->field]);

            return;
        }

        // Only an optimisation: the rule is the host's (promoteToDockerEngine), this spares a query per inventory.
        $candidate = $host->dockerIdentityCandidate();
        if (null === $candidate || null !== $this->hosts->findByIdentity($teamId, $candidate)) {
            return;
        }

        if ($host->promoteToDockerEngine()) {
            $this->audit->record($command, Outcome::success());
        }
    }
}
